###
#    Copyright 2015-2017 ppy Pty. Ltd.
#
#    This file is part of osu!web. osu!web is distributed with the hope of
#    attracting more community contributions to the core ecosystem of osu!.
#
#    osu!web is free software: you can redistribute it and/or modify
#    it under the terms of the Affero GNU General Public License version 3
#    as published by the Free Software Foundation.
#
#    osu!web is distributed WITHOUT ANY WARRANTY; without even the implied
#    warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
#    See the GNU Affero General Public License for more details.
#
#    You should have received a copy of the GNU Affero General Public License
#    along with osu!web.  If not, see <http://www.gnu.org/licenses/>.
###

{div, a, i, input, h1, h2, li, ol, span} = ReactDOMFactories
el = React.createElement

class Beatmaps.SearchPanel extends React.PureComponent
  constructor: (props) ->
    super props

    @prevText = null
    @debouncedSubmit = _.debounce @submit, 500


  componentDidMount: =>
    $(document).on 'turbolinks:before-cache.beatmaps-search-cache', @componentWillUnmount


  componentWillUnmount: =>
    $(document).off '.beatmaps-search-cache'
    @debouncedSubmit.cancel()


  render: =>
    div null,
      if StickyHeader.breadcrumbsElement()?
        ReactDOM.createPortal @renderBreadcrumbs(), StickyHeader.breadcrumbsElement()

      if StickyHeader.contentElement()?
        ReactDOM.createPortal @renderStickyContent(), StickyHeader.contentElement()

      div
        className: 'osu-page osu-page--beatmapsets-search-header'
        if currentUser.id?
          @renderUser()
        else
          @renderGuest()


  renderBreadcrumbs: =>
    return null unless currentUser.id?

    # TODO: replace with component that takes an array of {name, link}.
    ol className: 'sticky-header-breadcrumbs',
      li className: 'sticky-header-breadcrumbs__item',
        span
          className: 'sticky-header-breadcrumbs__link'
          osu.trans 'beatmapsets.index.guest_title'

      li className: 'sticky-header-breadcrumbs__item',
        span
          className: 'sticky-header-breadcrumbs__link'
          osu.trans 'home.search.title'


  renderStickyContent: =>
    return null unless currentUser.id?

    div
      className: 'beatmapsets-search beatmapsets-search--sticky'
      div
        className: 'beatmapsets-search__input-container'
        input
          className: 'beatmapsets-search__input js-beatmapsets-search-input'
          type: 'textbox'
          name: 'search'
          placeholder: osu.trans('beatmaps.listing.search.prompt')
          onInput: @onInput
          defaultValue: @props.filters.query
        div className: 'beatmapsets-search__icon',
          i className: 'fas fa-search'

      div
        className: 'beatmapsets-search__filters'
        @renderFilter
          name: 'status'
          options: @props.availableFilters.statuses
          showTitle: false

        @renderFilter
          name: 'mode'
          options: @props.availableFilters.modes
          showTitle: false


  onInput: (event) =>
    event.persist()
    @debouncedSubmit event


  renderFilter: ({ multiselect = false, name, options, showTitle = true }) =>
    el Beatmaps.SearchFilter,
      filters: @props.filters
      name: name
      title: osu.trans("beatmaps.listing.search.filters.#{name}") if showTitle
      options: options
      default: @props.filterDefaults[name]
      multiselect: multiselect
      selected: @props.filters[name]


  renderGuest: =>
    div
      ref: @props.innerRef
      className: 'beatmapsets-search'
      div
        className: 'osu-page-header__background'
        style:
          backgroundImage: osu.urlPresence(@props.background)
      div className: 'beatmapsets-search__input-container js-user-link',
        input
          className: 'beatmapsets-search__input'
          disabled: true
          type: 'textbox'
          placeholder: osu.trans('beatmaps.listing.search.login_required')
        div className: 'beatmapsets-search__icon',
          i className: 'fas fa-search'


  renderUser: =>
    filters = @props.availableFilters
    cssClasses = 'beatmapsets-search'
    cssClasses += ' beatmapsets-search--expanded' if @props.isExpanded

    div
      ref: @props.innerRef
      className: cssClasses
      div
        className: 'beatmapsets-search__background'
        style:
          backgroundImage: osu.urlPresence(@props.background)
      div className: 'beatmapsets-search__input-container',
        input
          className: 'beatmapsets-search__input js-beatmapsets-search-input'
          type: 'textbox'
          name: 'search'
          placeholder: osu.trans('beatmaps.listing.search.prompt')
          onInput: @onInput
          defaultValue: @props.filters.query
        div className: 'beatmapsets-search__icon',
          i className: 'fas fa-search'

      @renderFilter
        multiselect: true
        name: 'general'
        options: filters.general

      @renderFilter
        name: 'mode'
        options: filters.modes

      @renderFilter
        name: 'status'
        options: filters.statuses

      a
        className: 'beatmapsets-search__expand-link'
        href: '#'
        onClick: @props.expand
        div {}, osu.trans('beatmaps.listing.search.options')
        div {}, i className: 'fas fa-angle-down'

      div className: 'beatmapsets-search__advanced',
        @renderFilter
          name: 'genre'
          options: filters.genres

        @renderFilter
          name: 'language'
          options: filters.languages

        @renderFilter
          multiselect: true
          name: 'extra'
          options: filters.extras

        @renderFilter
          multiselect: true
          name: 'rank'
          options: filters.ranks

        @renderFilter
          name: 'played'
          options: filters.played


  submit: (e) =>
    text = e.target.value.trim()

    if text == @prevText
      return

    @prevText = text

    $(document).trigger 'beatmap:search:filtered', query: text
