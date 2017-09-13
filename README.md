# link-check

`kelunik/link-check` is a small script to check one or several URLs for broken links. It will crawl all pages linked from the initial ones, but will stay on the set of domains specified in the initial set of URLs.

Currently it reports any missing pages, request errors, and missing sections within a page which are linked via fragments.

```plain
composer install
bin/link-check https://amphp.org/ https://amphp.org/aerys
```

Specifying multiple URLs might be necessary if not all pages can be reached from the start page. But if that's the case, you should maybe better fix your website to make everything (indirectly) accessible from the start page.