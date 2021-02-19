<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

namespace App\Http\Controllers\Forum;

use App\Exceptions\ModelNotSavedException;
use App\Jobs\Notifications\ForumTopicReply;
use App\Libraries\DbCursorHelper;
use App\Libraries\NewForumTopic;
use App\Models\Forum\FeatureVote;
use App\Models\Forum\Forum;
use App\Models\Forum\PollOption;
use App\Models\Forum\Post;
use App\Models\Forum\Topic;
use App\Models\Forum\TopicCover;
use App\Models\Forum\TopicPoll;
use App\Models\Forum\TopicWatch;
use App\Transformers\Forum\TopicCoverTransformer;
use Auth;
use DB;
use Request;

/**
 * @group Forum
 */
class TopicsController extends Controller
{
    public function __construct()
    {
        parent::__construct();

        $this->middleware('auth', ['only' => [
            'create',
            'lock',
            'preview',
            'reply',
            'store',
        ]]);

        $this->middleware('require-scopes:public', ['only' => ['show']]);
        $this->middleware('require-scopes:forum.write', ['only' => ['reply', 'store', 'update']]);
    }

    public function create()
    {
        $forum = Forum::findOrFail(request('forum_id'));

        priv_check('ForumTopicStore', $forum)->ensureCan();

        return ext_view(
            'forum.topics.create',
            (new NewForumTopic($forum, Auth::user()))->toArray()
        );
    }

    public function destroy($id)
    {
        $topic = Topic::withTrashed()->findOrFail($id);

        priv_check('ForumTopicDelete', $topic)->ensureCan();

        DB::transaction(function () use ($topic) {
            if ((auth()->user()->user_id ?? null) !== $topic->topic_poster) {
                $this->logModerate(
                    'LOG_DELETE_TOPIC',
                    [$topic->topic_title],
                    $topic
                );
            }

            if (!$topic->delete()) {
                throw new ModelNotSavedException($topic->validationErrors()->toSentence());
            }
        });

        if (priv_check('ForumModerate', $topic->forum)->can()) {
            return ext_view('forum.topics.delete', ['post' => $topic->firstPost], 'js');
        } else {
            return ujs_redirect(route('forum.forums.show', $topic->forum));
        }
    }

    public function restore($id)
    {
        $topic = Topic::withTrashed()->findOrFail($id);

        priv_check('ForumModerate', $topic->forum)->ensureCan();

        DB::transaction(function () use ($topic) {
            $this->logModerate(
                'LOG_RESTORE_TOPIC',
                [$topic->topic_title],
                $topic
            );

            if (!$topic->restore()) {
                throw new ModelNotSavedException($topic->validationErrors()->toSentence());
            }
        });

        return ext_view('forum.topics.restore', ['post' => $topic->firstPost], 'js');
    }

    public function editPollGet($topicId)
    {
        $topic = Topic::findOrFail($topicId);

        priv_check('ForumTopicPollEdit', $topic)->ensureCan();

        return ext_view('forum.topics._edit_poll', compact('topic'));
    }

    public function editPollPost($topicId)
    {
        $topic = Topic::findOrFail($topicId);

        priv_check('ForumTopicPollEdit', $topic)->ensureCan();

        $poll = (new TopicPoll())->fill($this->getPollParams());
        $poll->setTopic($topic);

        $topic->getConnection()->transaction(function () use ($poll, $topic) {
            if (!$poll->save()) {
                return;
            }

            if (Auth::user()->getKey() !== $topic->topic_poster) {
                $this->logModerate(
                    'LOG_EDIT_POLL',
                    [$topic->poll_title],
                    $topic
                );
            }
        });

        if ($poll->validationErrors()->isAny()) {
            return error_popup($poll->validationErrors()->toSentence());
        }

        $pollSummary = PollOption::summary($topic, Auth::user());
        $canEditPoll = $poll->canEdit();

        return ext_view('forum.topics._poll', compact('canEditPoll', 'pollSummary', 'topic'));
    }

    public function issueTag($id)
    {
        $topic = Topic::findOrFail($id);

        priv_check('ForumModerate', $topic->forum)->ensureCan();

        $issueTag = presence(Request::input('issue_tag'));
        $state = get_bool(Request::input('state'));
        $type = 'issue_tag_'.$issueTag;

        if ($issueTag === null || !$topic->isIssue() || !in_array($issueTag, $topic::ISSUE_TAGS, true)) {
            abort(422);
        }

        $this->logModerate('LOG_ISSUE_TAG', compact('issueTag', 'state'), $topic);

        $method = $state ? 'setIssueTag' : 'unsetIssueTag';

        $topic->$method($issueTag);

        return ext_view('forum.topics.replace_button', compact('topic', 'type', 'state'), 'js');
    }

    public function lock($id)
    {
        $topic = Topic::withTrashed()->findOrFail($id);

        priv_check('ForumModerate', $topic->forum)->ensureCan();

        $type = 'lock';
        $state = get_bool(Request::input('lock'));
        $this->logModerate($state ? 'LOG_LOCK' : 'LOG_UNLOCK', [$topic->topic_title], $topic);
        $topic->lock($state);

        return ext_view('forum.topics.replace_button', compact('topic', 'type', 'state'), 'js');
    }

    public function move($id)
    {
        $topic = Topic::withTrashed()->findOrFail($id);
        $originForum = $topic->forum;
        $destinationForum = Forum::findOrFail(Request::input('destination_forum_id'));

        priv_check('ForumModerate', $originForum)->ensureCan();
        priv_check('ForumModerate', $destinationForum)->ensureCan();

        $this->logModerate('LOG_MOVE', [$originForum->forum_name], $topic);
        if ($topic->moveTo($destinationForum)) {
            return ext_view('layout.ujs-reload', [], 'js');
        } else {
            abort(422);
        }
    }

    public function pin($id)
    {
        $topic = Topic::withTrashed()->findOrFail($id);

        priv_check('ForumModerate', $topic->forum)->ensureCan();

        $type = 'moderate_pin';
        $state = get_int(Request::input('pin'));
        DB::transaction(function () use ($topic, $state) {
            $topic->pin($state);

            $this->logModerate(
                'LOG_TOPIC_TYPE',
                ['title' => $topic->topic_title, 'type' => $topic->topic_type],
                $topic
            );
        });

        return ext_view('forum.topics.replace_button', compact('topic', 'type', 'state'), 'js');
    }

    /**
     * Reply topic
     *
     * Create a post replying to the specified topic.
     *
     * ---
     *
     * ### Response Format
     *
     * [ForumPost](#forum-post) with `body` included.
     *
     * @urlParam topic required Id of the topic to be replied to. Example: 1
     *
     * @bodyParam body string required Content of the reply post. Example: hello
     */
    public function reply($id)
    {
        $topic = Topic::findOrFail($id);

        priv_check('ForumTopicReply', $topic)->ensureCan();

        $post = Post::createNew($topic, auth()->user(), get_string(request('body')));

        $post->markRead(Auth::user());
        (new ForumTopicReply($post, auth()->user()))->dispatch();

        if (is_api_request()) {
            return json_item($post, 'Forum\Post', ['body']);
        } else {
            return ext_view('forum.topics._posts', [
                'firstPostPosition' => $topic->postPosition($post->post_id),
                'posts' => collect([$post]),
                'topic' => $topic,
            ]);
        }
    }

    /**
     * Get Topic and Posts
     *
     * Get topic and its posts.
     *
     * ---
     *
     * ### Response Format
     *
     * Field  | Type                       | Includes
     * ------ | -------------------------- | --------
     * topic  | [ForumTopic](#forum-topic) | |
     * posts  | [ForumPost](#forum-post)[] | body
     * cursor | [Cursor](#cursor)          | |
     * sort   | ForumPostSort              | |
     *
     * @urlParam topic Id of the topic. Example: 1
     *
     * @queryParam cursor [Cursor](#cursor) for pagination. No-example
     * @queryParam sort Post sorting option. Valid values are `id_asc` (default) and `id_desc`. No-example
     * @queryParam limit Maximum number of posts to be returned (20 default, 50 at most). No-example
     * @queryParam start First post id to be returned with `sort` set to `id_asc`. This parameter is ignored if `cursor` is specified. No-example
     * @queryParam end First post id to be returned with `sort` set to `id_desc`. This parameter is ignored if `cursor` is specified. No-example
     *
     * @response {
     *   "topic": { "id": 1, "...": "..." },
     *   "posts": [
     *     { "id": 1, "...": "..." },
     *     { "id": 2, "...": "..." }
     *   ],
     *   "cursor": { "post_id": 1 },
     *   "sort": "id_asc"
     * }
     */
    public function show($id)
    {
        $params = get_params(request()->all(), null, [
            'start', // either number or "unread"
            'end:int',
            'n:int',

            'skip_layout:bool',
            'with_deleted:bool',

            'sort:string',
            'cursor:any',
            'limit:int',
        ], ['null_missing' => true]);

        $isJsonRequest = is_api_request();
        $skipLayout = $params['skip_layout'] ?? false;
        $showDeleted = $params['with_deleted'];
        $jumpTo = null;
        $currentUser = auth()->user();
        $limit = clamp($params['limit'] ?? 20, 1, 50);

        $topic = Topic::with(['forum'])->withTrashed()->findOrFail($id);

        $userCanModerate = priv_check('ForumModerate', $topic->forum)->can();

        if ($topic->trashed() && !$userCanModerate) {
            abort(404);
        }

        if ($topic->forum === null) {
            abort(404);
        }

        if ($userCanModerate) {
            $showDeleted = $showDeleted ?? $currentUser->profileCustomization()->forum_posts_show_deleted;
        } else {
            $showDeleted = false;
        }

        priv_check('ForumView', $topic->forum)->ensureCan();

        if ($params['cursor'] === null) {
            if ($params['start'] === 'unread') {
                $params['start'] = Post::lastUnreadByUser($topic, $currentUser);
            } else {
                $params['start'] = get_int($params['start']);
            }

            if ($params['n'] !== null) {
                $post = $topic->nthPost($params['n']);
                if ($post !== null) {
                    $params['cursor'] = ['post_id' => $post->post_id - 1];
                    $params['sort'] = 'id_asc';
                }
            } elseif ($params['start'] !== null) {
                $params['cursor'] = ['post_id' => $params['start'] - 1];
                $params['sort'] = 'id_asc';
            } elseif ($params['end'] !== null) {
                $params['cursor'] = ['post_id' => $params['end'] + 1];
                $params['sort'] = 'id_desc';
            }
        }

        $cursorHelper = new DbCursorHelper(Post::SORTS, Post::DEFAULT_SORT, $params['sort']);

        $postsQueryBase = $topic->posts()->showDeleted($showDeleted)->limit($limit);
        $posts = (clone $postsQueryBase)->cursorSort(
            $cursorHelper->getSort(),
            $cursorHelper->prepare($params['cursor'])
        )->get();

        if (!$isJsonRequest && $posts->count() === 0) {
            abort($skipLayout ? 204 : 404);
        }

        if (!$isJsonRequest && !$skipLayout) {
            $firstPost = $posts->first();
            $jumpTo = $firstPost->getKey();

            if ($cursorHelper->getSortName() === 'id_asc') {
                if ($jumpTo !== $topic->topic_first_post_id) {
                    $extraSort = 'id_desc';
                }
            } else {
                $extraSort = 'id_asc';
            }
            if (isset($extraCursorHelper)) {
                $extraCursorHelper = new DbCursorHelper(Post::SORTS, $extraSort);
                $extraPosts = (clone $postsQueryBase)
                    ->cursorSort(
                        $extraCursorHelper->getSort(),
                        $extraCursorHelper->prepare(['post_id' => $jumpTo])
                    )->get()
                    ->reverse();

                $posts = $extraPosts->concat($posts);
            }
        }

        $posts = $posts
            ->load([
                'lastEditor',
                'user.country',
                'user.rank',
                'user.supporterTagPurchases',
                'user.userGroups',
            ])->each(function ($item) use ($topic) {
                $item
                    ->setRelation('forum', $topic->forum)
                    ->setRelation('topic', $topic);
            });

        if ($isJsonRequest) {
            return [
                'topic' => json_item($topic, 'Forum\Topic'),
                'posts' => json_collection($posts, 'Forum\Post', ['body']),
                'cursor' => $cursorHelper->next($posts),
                'sort' => $cursorHelper->getSortName(),
            ];
        }

        if ($cursorHelper->getSortName() === 'id_desc') {
            $posts = $posts->reverse();
        }

        $posts->last()->markRead($currentUser);

        $coverModel = $topic->cover()->firstOrNew([]);
        $coverModel->setRelation('topic', $topic);
        $cover = json_item($coverModel, new TopicCoverTransformer());

        $poll = $topic->poll();
        if ($poll->exists()) {
            $topic->load([
                'pollOptions.votes',
                'pollOptions.post',
            ]);
            $canEditPoll = $poll->canEdit() && priv_check('ForumTopicPollEdit', $topic)->can();
        } else {
            $canEditPoll = false;
        }
        $pollSummary = PollOption::summary($topic, $currentUser);

        $watch = TopicWatch::lookup($topic, $currentUser);

        $featureVotes = $this->groupFeatureVotes($topic);

        $noindex = !$topic->forum->enable_indexing;
        $template = $skipLayout ? '_posts' : 'show';

        $firstPostId = $topic->topic_first_post_id;
        $firstShownPostId = $posts->first()->post_id;

        // position of the first post, incremented in the view
        // to generate positions of further posts
        $firstPostPosition = $topic->postPosition($firstShownPostId);

        return ext_view(
            "forum.topics.{$template}",
            compact(
                'canEditPoll',
                'cover',
                'watch',
                'jumpTo',
                'pollSummary',
                'posts',
                'featureVotes',
                'firstPostPosition',
                'firstPostId',
                'noindex',
                'topic',
                'userCanModerate',
                'showDeleted'
            )
        );
    }


    /**
     * Create Topic
     *
     * Create a new topic.
     *
     * ---
     *
     * ### Response Format
     *
     * Field  | Type                       | Includes
     * ------ | -------------------------- | --------
     * topic  | [ForumTopic](#forum-topic) | |
     * post   | [ForumPost](#forum-post)   | body
     *
     * @bodyParam body string required Content of the topic. Example: hello
     * @bodyParam forum_id number required Forum to create the topic in. Example: 1
     * @bodyParam title string required Title of the topic. Example: untitled
     * @bodyParam with_poll boolean Enable this to also create poll in the topic (default: false). Example: 1
     * @bodyParam forum_topic_poll[hide_results] boolean Enable this to hide result until voting period ends (default: false). No-example
     * @bodyParam forum_topic_poll[length_days] number Number of days for voting period. 0 means the voting will never ends (default: 0). This parameter is required if `hide_results` option is enabled. No-example
     * @bodyParam forum_topic_poll[max_options] number Maximum number of votes each user can cast (default: 1). No-example
     * @bodyParam forum_topic_poll[options] string required Newline-separated list of voting options. BBCode is supported. Example: item A...
     * @bodyParam forum_topic_poll[title] string required Title of the poll. Example: my poll
     * @bodyParam forum_topic_poll[vote_change] boolean Enable this to allow user to change their votes (default: false). No-example
     */
    public function store()
    {
        $params = get_params(request()->all(), null, [
            'body:string',
            'cover_id:int',
            'forum_id:int',
            'title:string',
            'with_poll:bool',
        ], ['null_missing' => true]);

        $forum = Forum::findOrFail($params['forum_id']);
        $user = auth()->user();

        priv_check('ForumTopicStore', $forum)->ensureCan();

        if ($params['with_poll']) {
            $poll = (new TopicPoll())->fill($this->getPollParams());

            if (!$poll->isValid()) {
                return error_popup($poll->validationErrors()->toSentence());
            }
        }

        $topicParams = [
            'title' => $params['title'],
            'user' => $user,
            'body' => $params['body'],
            'cover' => TopicCover::findForUse($params['cover_id'], $user),
        ];

        $topic = Topic::createNew($forum, $topicParams, $poll ?? null);

        if ($user->user_notify || $forum->isHelpForum()) {
            TopicWatch::setState($topic, $user, 'watching_mail');
        }

        $post = $topic->posts->last();
        $post->markRead($user);

        if (is_api_request()) {
            return [
                'topic' => json_item($topic, 'Forum\Topic'),
                'post' => json_item($post, 'Forum\Post', ['body']),
            ];
        } else {
            return ujs_redirect(route('forum.topics.show', $topic));
        }
    }

    /**
     * Edit Topic
     *
     * Edit topic. Only title can be edited through this endpoint.
     *
     * ---
     *
     * ### Response Format
     *
     * The edited [ForumTopic](#forum-topic).
     *
     * @urlParam topic required Id of the topic. Example: 1
     * @bodyParam forum_topic[topic_title] string New topic title. Example: titled
     */
    public function update($id)
    {
        $topic = Topic::withTrashed()->findOrFail($id);

        if (!priv_check('ForumTopicEdit', $topic)->can()) {
            abort(403);
        }

        $params = get_params(request()->all(), 'forum_topic', ['topic_title']);

        if ($topic->update($params)) {
            if ((Auth::user()->user_id ?? null) !== $topic->topic_poster) {
                $this->logModerate(
                    'LOG_EDIT_TOPIC',
                    [$topic->topic_title],
                    $topic
                );
            }

            if (is_api_request()) {
                return json_item($topic, 'Forum\Topic');
            } else {
                return response(null, 204);
            }
        } else {
            return error_popup($topic->validationErrors()->toSentence());
        }
    }

    public function vote($topicId)
    {
        $topic = Topic::findOrFail($topicId);

        priv_check('ForumTopicVote', $topic)->ensureCan();

        $params = get_params(Request::input(), 'forum_topic_vote', ['option_ids:int[]']);
        $params['user_id'] = Auth::user()->user_id;
        $params['ip'] = Request::ip();

        if ($topic->vote()->fill($params)->save()) {
            return ujs_redirect(route('forum.topics.show', $topic->topic_id));
        } else {
            return error_popup($topic->vote()->validationErrors()->toSentence());
        }
    }

    public function voteFeature($topicId)
    {
        $star = FeatureVote::createNew([
            'user_id' => Auth::user()->user_id,
            'topic_id' => $topicId,
        ]);

        if ($star->getKey() !== null) {
            return ujs_redirect(route('forum.topics.show', $topicId));
        } else {
            return error_popup($star->validationErrors()->toSentence());
        }
    }

    private function getPollParams()
    {
        return get_params(request()->all(), 'forum_topic_poll', [
            'hide_results:bool',
            'length_days:int',
            'max_options:int',
            'options:string_split',
            'title',
            'vote_change:bool',
        ]);
    }

    private function groupFeatureVotes($topic)
    {
        if (!$topic->isFeatureTopic()) {
            return [];
        }

        $ret = [];

        foreach ($topic->featureVotes()->with('user')->get() as $vote) {
            $username = optional($vote->user)->username;
            $ret[$username] ?? ($ret[$username] = 0);
            $ret[$username] += $vote->voteIncrement();
        }

        arsort($ret);

        return $ret;
    }
}
