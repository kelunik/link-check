<?php

namespace Kelunik\LinkCheck;

use Amp\Artax\Client;
use Amp\Artax\Response;
use Amp\Artax\TimeoutException;
use Amp\Uri\Uri;
use Room11\DOMUtils\ElementNotFoundException;
use function Amp\asyncCall;
use function Room11\DOMUtils\domdocument_load_html;
use function Room11\DOMUtils\xpath_get_elements;

class LinkCheck {
    const MAX_CONCURRENT_REQUESTS = 10;

    private $currentRequests = 0;
    private $queue = [];

    private $visitedUris = [];
    private $seenUriVariations = [];
    private $existingUriVariations = [];
    private $links = [];

    private $domains = [];
    private $httpClient;

    public function __construct(Client $httpClient) {
        $this->httpClient = $httpClient;
    }

    public function addDomain(string $domain): void {
        $this->domains[$domain] = $domain;
    }

    public function enqueue(string $uri): void {
        $uri = (new Uri($uri))->normalize();

        if (isset($this->queue[$uri])) {
            return;
        }

        $this->queue[$uri] = $uri;

        if ($this->currentRequests < self::MAX_CONCURRENT_REQUESTS) {
            $this->dequeue();
        }
    }

    private function dequeue(): void {
        assert(count($this->queue) > 0, "Queue is empty, can't dequeue().");

        $uri = new Uri(array_shift($this->queue));

        $this->currentRequests++;
        $this->visitedUris[$uri->getAbsoluteUri()] = -1; // pending

        asyncCall(function () use ($uri) {
            /** @var Response $response */
            try {
                $response = yield $this->httpClient->request($uri->normalize());
                $body = yield $response->getBody();

                print ".";
            } catch (TimeoutException $e) {
                $this->currentRequests--;

                if ($this->queue) {
                    $this->dequeue();
                }

                print "T";

                return;
            }

            $this->visitedUris[$uri->getAbsoluteUri()] = $response->getStatus();

            if ($response->getStatus() === 200 && strtok($response->getHeader("content-type") ?? "", ";") === "text/html") {
                // Use the requests URI, which might not be the original one due to redirects
                $this->processHtmlBody(new Uri($response->getRequest()->getUri()), $body);
            }

            $this->currentRequests--;

            if ($this->queue) {
                $this->dequeue();
            }
        });
    }

    private function processHtmlBody(Uri $uri, string $body): void {
        $dom = domdocument_load_html($body);

        try {
            $hrefs = xpath_get_elements($dom, "//a");
        } catch (ElementNotFoundException $e) {
            $hrefs = [];
        }

        foreach ($hrefs as $href) {
            $href = $href->getAttribute("href");

            if ($href === "#") {
                continue;
            }

            $href = $uri->resolve($href);

            $this->links[$href->getAbsoluteUri()][$uri->getAbsoluteUri()] = true;
            $this->links[$href->normalize()][$uri->getAbsoluteUri()] = true;
            $this->seenUriVariations[$href->getAbsoluteUri()][$href->normalize()] = $href->normalize();

            if (isset($this->visitedUris[$href->getAbsoluteUri()])) {
                continue;
            }

            if (in_array($href->getScheme(), ["http", "https"], true) && isset($this->domains[$href->getHost()])) {
                $this->enqueue($href->normalize());
            }
        }

        try {
            $sections = xpath_get_elements($dom, "//*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6]");
        } catch (ElementNotFoundException $e) {
            $sections = [];
        }

        foreach ($sections as $section) {
            $section = $section->getAttribute("id");

            if ($section === "") {
                continue;
            }

            $this->existingUriVariations[$uri->getAbsoluteUri() . "#" . $section] = $uri->getAbsoluteUri() . "#" . $section;
        }
    }

    public function getVisitedUris(): array {
        return $this->visitedUris;
    }

    public function getSeenUriVariations(): array {
        return $this->seenUriVariations;
    }

    public function getExistingUriVariations(): array {
        return $this->existingUriVariations;
    }

    public function getLinks(string $uri): array {
        return array_keys($this->links[$uri] ?? []);
    }
}