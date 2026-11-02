/*!
 * rss-block.js, part of timvandendries/php-rss-parser (MIT license).
 * Shows RSS and Atom feeds as blocks of links: <div data-rss-feed="https://…"></div>.
 * Gets the feeds from a FeedEndpoint, see the README. Needs no other scripts.
 */
(() => {
    'use strict';

    const script = document.currentScript;

    // Where blocks get their feeds when they don't set data-rss-endpoint: this script tag's data-endpoint,
    // else the endpoint that served this script (rss.php?asset=js), else /rss.php.
    const defaultEndpoint = script?.dataset.endpoint
        || (script && /[?&]asset=/.test(script.src) ? script.src.split('?')[0] : '/rss.php');

    const texts = {
        en: { loading: 'Loading…', error: 'Could not load this feed.', empty: 'This feed has no items.' },
        nl: { loading: 'Laden…', error: 'Deze feed kon niet worden geladen.', empty: 'Deze feed heeft geen berichten.' },
    };
    const lang = (script?.dataset.lang || document.documentElement.lang || 'en').slice(0, 2).toLowerCase();

    // The settings each block was last rendered with, and its refresh timer
    const current = new WeakMap();
    const timers = new WeakMap();

    const RssBlock = {
        // Texts the blocks show, in the page's language (Dutch or English). Change them in a script
        // right after this one: RssBlock.text.loading = 'Chargement…';
        text: { ...(texts[lang] || texts.en) },

        /**
         * Renders every element with a data-rss-feed attribute inside root that isn't a block yet.
         */
        renderAll(root = document) {
            root.querySelectorAll('[data-rss-feed]').forEach((element) => {
                if (!current.has(element)) {
                    RssBlock.render(element);
                }
            });
        },

        /**
         * Shows a feed in element. Options take the place of the element's data-rss-* attributes:
         * feed, endpoint, limit, title, dates (false hides them) and refresh (seconds).
         * The promise settles once the items or the error message are shown.
         */
        async render(element, options = {}) {
            const data = element.dataset;
            const settings = {
                feed: options.feed ?? data.rssFeed,
                endpoint: options.endpoint ?? data.rssEndpoint ?? defaultEndpoint,
                limit: options.limit ?? data.rssLimit,
                title: options.title ?? data.rssTitle,
                dates: options.dates ?? (data.rssDates !== 'false'),
                refresh: Number(options.refresh ?? data.rssRefresh) || 0,
            };
            if (!settings.feed) {
                throw new Error('RssBlock.render() needs a feed: set data-rss-feed or options.feed');
            }

            current.set(element, settings);
            clearInterval(timers.get(element));
            if (settings.refresh > 0) {
                timers.set(element, setInterval(() => {
                    if (element.isConnected) {
                        load(element, settings);
                    } else {
                        clearInterval(timers.get(element));
                    }
                }, settings.refresh * 1000));
            }

            element.classList.add('rss-block');
            element.replaceChildren(header(settings.title || hostOf(settings.feed)), message(RssBlock.text.loading));
            await load(element, settings);
        },
    };

    async function load(element, settings) {
        const url = new URL(settings.endpoint, document.baseURI);
        url.searchParams.set('feed', settings.feed);
        if (settings.limit) {
            url.searchParams.set('limit', settings.limit);
        }

        element.setAttribute('aria-busy', 'true');
        try {
            const response = await fetch(url, { headers: { Accept: 'application/json' } });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            const feed = await response.json();
            // Rendered again with other settings while this one loaded
            if (current.get(element) !== settings) {
                return;
            }
            element.replaceChildren(
                header(settings.title || feed.title || hostOf(settings.feed), feed.siteUrl),
                itemList(feed.items, settings.dates),
            );
        } catch (error) {
            console.error(`rss-block: could not load ${settings.feed}`, error);
            // On a refresh, the items already shown beat an error message
            if (current.get(element) === settings && !element.querySelector('.rss-block__items')) {
                element.replaceChildren(header(settings.title || hostOf(settings.feed)), message(RssBlock.text.error));
            }
        } finally {
            element.removeAttribute('aria-busy');
        }
    }

    function header(title, siteUrl) {
        const href = httpUrl(siteUrl);
        const name = node(href ? 'a' : 'span', 'rss-block__name', title);
        if (href) {
            name.href = href;
            name.target = '_blank';
            name.rel = 'noopener noreferrer';
        }
        return node('div', 'rss-block__header', name);
    }

    function itemList(items, showDates) {
        if (!Array.isArray(items) || items.length === 0) {
            return message(RssBlock.text.empty);
        }

        const list = node('ul', 'rss-block__items');
        for (const item of items) {
            const title = String(item.title || item.itemUrl || '');
            const href = httpUrl(item.itemUrl);
            const row = node(href ? 'a' : 'span', 'rss-block__link');
            if (href) {
                row.href = href;
                row.target = '_blank';
                row.rel = 'noopener noreferrer';
            }
            row.title = title;

            const date = showDates ? item.published || item.updated : null;
            const label = date ? dateLabel(date) : null;
            if (label) {
                const time = node('time', 'rss-block__date', label);
                time.dateTime = date;
                row.append(time);
            }
            row.append(node('span', 'rss-block__title', title));
            list.append(node('li', 'rss-block__item', row));
        }
        return list;
    }

    function message(text) {
        return node('p', 'rss-block__message', text);
    }

    // An element with a class and, optionally, a child node or text. Text is never parsed as HTML.
    function node(tag, className, child) {
        const element = document.createElement(tag);
        element.className = className;
        if (child !== undefined) {
            element.append(child);
        }
        return element;
    }

    // The URL when it is http(s), so a javascript: link in a feed can't run
    function httpUrl(value) {
        try {
            const url = new URL(value);
            return url.protocol === 'http:' || url.protocol === 'https:' ? url.href : null;
        } catch {
            return null;
        }
    }

    function hostOf(feed) {
        try {
            return new URL(feed, document.baseURI).hostname;
        } catch {
            return feed;
        }
    }

    const pad = (n) => String(n).padStart(2, '0');

    // The time (14:05) for an item from today, otherwise the date (30-10)
    function dateLabel(value) {
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) {
            return null;
        }
        return date.toDateString() === new Date().toDateString()
            ? `${pad(date.getHours())}:${pad(date.getMinutes())}`
            : `${pad(date.getDate())}-${pad(date.getMonth() + 1)}`;
    }

    window.RssBlock = RssBlock;

    // Once the page is parsed, so a script right after this one can still change RssBlock.text
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => RssBlock.renderAll());
    } else {
        RssBlock.renderAll();
    }
})();
