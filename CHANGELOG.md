# Changelog

## 1.3.7
- Finalize `[sra_knowledge_video]` shortcode for embedding the Knowledge Video associated with a Consumer Guide post.
- Use the actual `knowledge-video` custom post type key.
- Support ACF bidirectional relationship lookup with forward-relationship fallback.
- Support YouTube watch, Shorts, youtu.be, and embed URLs.
- Remove temporary admin-only Knowledge Video debug shortcode.
- No Knowledge Center search behavior changes.

## 1.3.6
- Fix Knowledge Video shortcode to use the actual custom post type key `knowledge-video`.
- Keep the temporary admin-only debug shortcode available for verification.
- No Knowledge Center search behavior changes.

## 1.3.4
- Make `[sra_knowledge_video]` use the actual queried article ID when Elementor changes the global post context.
- Read ACF relationship values through ACF when available, with post-meta fallback.
- Add a reverse-query fallback using `related_consumer_guide_article` so the embed still works if the bidirectional article field is unavailable.
- No Knowledge Center search behavior changes.

## 1.3.3
- Add `[sra_knowledge_video]` shortcode for embedding the Knowledge Video associated with a Consumer Guide post.
- Read the bidirectional `knowledge_video` ACF field and the selected Knowledge Video's `youtube_url`.
- Support YouTube watch, Shorts, youtu.be, and embed URLs.
- Render nothing when no published Knowledge Video is associated with the current post.
- Add compact responsive video styling without changing Knowledge Center search behavior.

## 1.3.1

- Added support for searching across multiple post categories.
- Added optional searching of WordPress Pages alongside Posts.
- Added a page-level Knowledge Center Search checkbox so Pages are included only when explicitly selected.
- Added priority phrase ranking to boost results that contain important multi-word phrases.
- Preserved category restrictions for Posts while allowing opted-in Pages to appear in the same search.


## 1.3.0

- Added privacy-conscious Knowledge Center analytics dashboard improvements.
- Added top clicked articles reporting.
- Added content opportunities and unanswered-question reporting.
- Added trending searches and period-over-period comparisons.
- Refactored analytics into separate storage, query, and dashboard classes.
- Removed anonymous session linking from search analytics.
- Restricted logged click destinations to local site URLs.
- Improved analytics dashboard styling and organization.

## 1.2.0
- Renamed the admin experience to Knowledge Center.
- Added privacy-conscious search and click analytics.
- Added dashboard cards for searches, click rate, no-result searches, and average results.
- Added reports for top searches, unanswered searches, low-click searches, and source pages.
- Added configurable retention and automatic daily cleanup.
- Added analytics enable/disable setting and a protected clear-data action.
- Preserved live search, ranking, synonyms, highlighting, keyboard navigation, and category filtering.

## 1.1.0
- Added configurable two-way synonyms and relevance ranking.
- Added a settings page and matching-term highlighting.

## 1.0.0
- Initial category-restricted live search plugin.
