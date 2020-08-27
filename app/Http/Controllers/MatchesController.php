<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

namespace App\Http\Controllers;

use App\Models\Match\Match;
use App\Models\User;
use App\Transformers\Match\EventTransformer;
use App\Transformers\Match\MatchTransformer;
use App\Transformers\UserCompactTransformer;

class MatchesController extends Controller
{
    public function show($id)
    {
        $match = Match::findOrFail($id);

        priv_check('MatchView', $match)->ensureCan();

        $eventsJson = $this->eventsJson([
            'match' => $match,
            'after' => request('after'),
            'before' => request('before'),
        ]);

        return ext_view('matches.index', compact('match', 'eventsJson'));
    }

    public function history($matchId)
    {
        $match = Match::findOrFail($matchId);

        priv_check('MatchView', $match)->ensureCan();

        return $this->eventsJson([
            'match' => $match,
            'after' => request('after'),
            'before' => request('before'),
            'limit' => request('limit'),
        ]);
    }

    private function eventsJson($params)
    {
        $match = $params['match'];
        $after = get_int($params['after'] ?? null);
        $before = get_int($params['before'] ?? null);
        $limit = clamp(get_int($params['limit'] ?? null) ?? 100, 1, 101);

        $events = $match->events()
            ->with([
                'game.beatmap.beatmapset',
                'game.scores' => function ($query) {
                    $query->with('game')->default();
                },
            ])->limit($limit);

        if (isset($after)) {
            $events
                ->where('event_id', '>', $after)
                ->orderBy('event_id', 'ASC');
        } else {
            if (isset($before)) {
                $events->where('event_id', '<', $before);
            }

            $events->orderBy('event_id', 'DESC');
            $reverseOrder = true;
        }

        $events = $events->get();

        if ($reverseOrder ?? false) {
            $events = $events->reverse();
        }

        $users = User::with('country')->whereIn('user_id', $this->usersFromEvents($events))->get();

        $users = json_collection(
            $users,
            new UserCompactTransformer,
            'country'
        );

        $events = json_collection(
            $events,
            new EventTransformer,
            ['game.beatmap.beatmapset', 'game.scores.match']
        );

        return [
            'match' => json_item($match, 'Match\Match'),
            'events' => $events,
            'users' => $users,
            'latest_event_id' => $match->events()->select('event_id')->last()->getKey(),
            'current_game_id' => optional($match->currentGame())->getKey(),
        ];
    }

    private function usersFromEvents($events)
    {
        $userIds = [];

        foreach ($events as $event) {
            if ($event->user_id) {
                $userIds[] = $event->user_id;
            }

            if ($event->game) {
                foreach ($event->game->scores as $score) {
                    $userIds[] = $score->user_id;
                }
            }
        }

        return array_unique($userIds);
    }
}
