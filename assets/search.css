(function () {
    'use strict';

    function initializeLiveSearch(search) {
        if (search.dataset.sraInitialized === 'true') return;

        const input = search.querySelector('.sra-live-search__input');
        const results = search.querySelector('.sra-live-search__results');

        if (
            !input ||
            !results ||
            typeof sraSearchSettings === 'undefined'
        ) {
            return;
        }

        search.dataset.sraInitialized = 'true';

        const category = search.dataset.category;
        const postTypes = search.dataset.postTypes || 'post';
        const noResultsText =
            search.dataset.noResults ||
            'No matching articles found.';

        let timer = null;
        let requestController = null;
        let activeIndex = -1;
        let eventToken = '';

        function getSelectableItems() {
            return Array.from(
                results.querySelectorAll(
                    '.sra-live-search__result'
                )
            );
        }

        function resetActiveItem() {
            getSelectableItems().forEach(function (item) {
                item.classList.remove('is-active');
                item.setAttribute('aria-selected', 'false');
            });

            activeIndex = -1;
        }

        function setActiveItem(index) {
            const items = getSelectableItems();

            if (!items.length) {
                activeIndex = -1;
                return;
            }

            if (index < 0) {
                index = items.length - 1;
            }

            if (index >= items.length) {
                index = 0;
            }

            resetActiveItem();

            activeIndex = index;

            items[activeIndex].classList.add('is-active');

            items[activeIndex].setAttribute(
                'aria-selected',
                'true'
            );

            items[activeIndex].scrollIntoView({
                block: 'nearest'
            });
        }

        function closeResults() {
            results.hidden = true;

            input.setAttribute(
                'aria-expanded',
                'false'
            );

            resetActiveItem();
        }

        function showMessage(message) {
            results.replaceChildren();

            const item = document.createElement('div');

            item.className =
                'sra-live-search__message';

            item.textContent = message;

            results.appendChild(item);

            results.hidden = false;

            input.setAttribute(
                'aria-expanded',
                'true'
            );

            resetActiveItem();
        }

        function appendHighlightedText(
            element,
            text,
            term
        ) {
            const normalizedTerm = term.trim();

            if (!normalizedTerm) {
                element.textContent = text;
                return;
            }

            const escapedTerm =
                normalizedTerm.replace(
                    /[.*+?^${}()|[\]\\]/g,
                    '\\$&'
                );

            const pattern = new RegExp(
                '(' + escapedTerm + ')',
                'gi'
            );

            text.split(pattern).forEach(
                function (part) {

                    if (
                        part.toLowerCase() ===
                        normalizedTerm.toLowerCase()
                    ) {
                        const mark =
                            document.createElement(
                                'mark'
                            );

                        mark.className =
                            'sra-live-search__highlight';

                        mark.textContent = part;

                        element.appendChild(mark);

                    } else {

                        element.appendChild(
                            document.createTextNode(
                                part
                            )
                        );
                    }
                }
            );
        }

        function logClick(
            link,
            clickType,
            position
        ) {
            if (
                !sraSearchSettings.analytics ||
                !eventToken
            ) {
                return;
            }

            const body = new URLSearchParams();

            body.set(
                'action',
                'sra_search_click'
            );

            body.set(
                'nonce',
                sraSearchSettings.nonce
            );

            body.set(
                'event_token',
                eventToken
            );

            body.set(
                'click_type',
                clickType
            );

            body.set(
                'position',
                String(position)
            );

            body.set(
                'url',
                link.href
            );

            fetch(
                sraSearchSettings.ajaxUrl,
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    keepalive: true,
                    headers: {
                        'Content-Type':
                            'application/x-www-form-urlencoded;charset=UTF-8'
                    },
                    body: body.toString()
                }
            ).catch(function () {});
        }

        function showPosts(
            posts,
            term
        ) {
            results.replaceChildren();

            resetActiveItem();

            const maxResults = Math.max(
                3,
                parseInt(
                    sraSearchSettings.maxResults,
                    10
                ) || 6
            );

            const displayedPosts =
                posts.slice(
                    0,
                    maxResults
                );

            displayedPosts.forEach(
                function (post, index) {

                    const link =
                        document.createElement(
                            'a'
                        );

                    link.className =
                        'sra-live-search__result';

                    link.href = post.url;

                    link.target = '_blank';

                    link.rel =
                        'noopener noreferrer';

                    link.setAttribute(
                        'role',
                        'option'
                    );

                    link.setAttribute(
                        'aria-selected',
                        'false'
                    );

                    link.addEventListener(
                        'click',
                        function () {
                            logClick(
                                link,
                                'result',
                                index + 1
                            );
                        }
                    );

                    if (post.thumbnail) {

                        const image =
                            document.createElement(
                                'img'
                            );

                        image.className =
                            'sra-live-search__thumb';

                        image.src =
                            post.thumbnail;

                        image.alt = '';

                        image.loading =
                            'lazy';

                        link.appendChild(
                            image
                        );
                    }

                    const content =
                        document.createElement(
                            'div'
                        );

                    content.className =
                        'sra-live-search__content';

                    const title =
                        document.createElement(
                            'div'
                        );

                    title.className =
                        'sra-live-search__title';

                    if (
                        sraSearchSettings.highlightTerms
                    ) {
                        appendHighlightedText(
                            title,
                            post.title,
                            term
                        );
                    } else {
                        title.textContent =
                            post.title;
                    }

                    content.appendChild(
                        title
                    );

                    if (post.excerpt) {

                        const excerpt =
                            document.createElement(
                                'div'
                            );

                        excerpt.className =
                            'sra-live-search__excerpt';

                        if (
                            sraSearchSettings.highlightTerms
                        ) {
                            appendHighlightedText(
                                excerpt,
                                post.excerpt,
                                term
                            );
                        } else {
                            excerpt.textContent =
                                post.excerpt;
                        }

                        content.appendChild(
                            excerpt
                        );
                    }

                    link.appendChild(
                        content
                    );

                    results.appendChild(
                        link
                    );
                }
            );

            results.hidden = false;

            input.setAttribute(
                'aria-expanded',
                'true'
            );
        }

        async function runSearch() {
            const term =
                input.value.trim();

            if (term.length < 2) {

                closeResults();

                results.replaceChildren();

                search.classList.remove(
                    'is-loading'
                );

                eventToken = '';

                return;
            }

            if (requestController) {
                requestController.abort();
            }

            requestController =
                new AbortController();

            search.classList.add(
                'is-loading'
            );

            const url = new URL(
                sraSearchSettings.ajaxUrl,
                window.location.origin
            );

            url.searchParams.set(
                'action',
                'sra_search'
            );

            url.searchParams.set(
                'term',
                term
            );

            url.searchParams.set(
                'category',
                category
            );

            url.searchParams.set(
                'post_types',
                postTypes
            );

            url.searchParams.set(
                'nonce',
                sraSearchSettings.nonce
            );

            url.searchParams.set(
                'source',
                window.location.href
            );

            try {

                const response =
                    await fetch(
                        url.toString(),
                        {
                            signal:
                                requestController.signal,
                            credentials:
                                'same-origin'
                        }
                    );

                if (!response.ok) {
                    throw new Error(
                        'Search request failed.'
                    );
                }

                const json =
                    await response.json();

                if (
                    !json.success ||
                    !json.data ||
                    !Array.isArray(
                        json.data.results
                    )
                ) {
                    throw new Error(
                        'Unexpected search response.'
                    );
                }

                eventToken =
                    json.data.eventToken ||
                    '';

                if (
                    json.data.results.length ===
                    0
                ) {
                    showMessage(
                        noResultsText
                    );

                    return;
                }

                showPosts(
                    json.data.results,
                    term
                );

            } catch (error) {

                if (
                    error.name !==
                    'AbortError'
                ) {
                    showMessage(
                        'Search is temporarily unavailable.'
                    );
                }

            } finally {

                search.classList.remove(
                    'is-loading'
                );
            }
        }

        input.addEventListener(
            'input',
            function () {

                window.clearTimeout(
                    timer
                );

                resetActiveItem();

                timer =
                    window.setTimeout(
                        runSearch,
                        450
                    );
            }
        );

        input.addEventListener(
            'keydown',
            function (event) {

                if (
                    event.key ===
                    'ArrowDown'
                ) {
                    const items =
                        getSelectableItems();

                    if (items.length) {
                        event.preventDefault();

                        setActiveItem(
                            activeIndex + 1
                        );
                    }

                    return;
                }

                if (
                    event.key ===
                    'ArrowUp'
                ) {
                    const items =
                        getSelectableItems();

                    if (items.length) {
                        event.preventDefault();

                        setActiveItem(
                            activeIndex - 1
                        );
                    }

                    return;
                }

                if (
                    event.key ===
                    'Enter'
                ) {
                    event.preventDefault();

                    if (activeIndex >= 0) {
                        const item =
                            getSelectableItems()[
                                activeIndex
                            ];

                        if (item) {
                            item.click();
                        }

                        return;
                    }

                    window.clearTimeout(
                        timer
                    );

                    runSearch();

                    return;
                }

                if (
                    event.key ===
                    'Escape'
                ) {
                    event.preventDefault();

                    closeResults();

                    input.blur();
                }
            }
        );

        input.addEventListener(
            'focus',
            function () {

                if (
                    results.children.length >
                    0
                ) {
                    results.hidden = false;

                    input.setAttribute(
                        'aria-expanded',
                        'true'
                    );
                }
            }
        );

        document.addEventListener(
            'click',
            function (event) {

                if (
                    !search.contains(
                        event.target
                    )
                ) {
                    closeResults();
                }
            }
        );
    }

    function initializeAllLiveSearches() {
        document
            .querySelectorAll(
                '.sra-live-search'
            )
            .forEach(
                initializeLiveSearch
            );
    }

    if (
        document.readyState ===
        'loading'
    ) {
        document.addEventListener(
            'DOMContentLoaded',
            initializeAllLiveSearches
        );
    } else {
        initializeAllLiveSearches();
    }

    const observer =
        new MutationObserver(
            initializeAllLiveSearches
        );

    observer.observe(
        document.documentElement,
        {
            childList: true,
            subtree: true
        }
    );
})();