<?php

declare(strict_types=1);

namespace App\Blog;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\FrontMatter\Data\SymfonyYamlFrontMatterParser;
use League\CommonMark\Extension\FrontMatter\Exception\InvalidFrontMatterException;
use League\CommonMark\Extension\FrontMatter\FrontMatterParser;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reads blog articles from markdown files committed in the git repository.
 *
 * Each `*.md` file under the articles directory carries YAML front matter for
 * its metadata; the filename (minus extension) becomes the URL slug. Metadata
 * is parsed lazily and memoized; the markdown body is only rendered to HTML
 * when a single article is requested for its detail page.
 */
final class ArticleRepository
{
    private const WORDS_PER_MINUTE = 200;
    private const SLUG_PATTERN = '/^[a-z0-9-]+$/';

    private ?MarkdownConverter $converter = null;
    private ?FrontMatterParser $frontMatter = null;

    /** @var array<string, Article>|null memoized metadata, keyed by slug */
    private ?array $index = null;

    /** @var list<Article>|null memoized index, most recent first */
    private ?array $sorted = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/content/articles')]
        private readonly string $articlesDir,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * All articles, most recent first.
     *
     * @return list<Article>
     */
    public function findAll(): array
    {
        if (null === $this->sorted) {
            $articles = array_values($this->loadIndex());
            usort($articles, static fn (Article $a, Article $b): int => $b->date <=> $a->date);
            $this->sorted = $articles;
        }

        return $this->sorted;
    }

    /**
     * The single featured article, falling back to the most recent one.
     */
    public function findFeatured(): ?Article
    {
        $all = $this->findAll();

        foreach ($all as $article) {
            if ($article->featured) {
                return $article;
            }
        }

        return $all[0] ?? null;
    }

    /**
     * Every article except the given slug, most recent first.
     *
     * @return list<Article>
     */
    public function findAllExcept(string $slug): array
    {
        return array_values(array_filter(
            $this->findAll(),
            static fn (Article $a): bool => $a->slug !== $slug,
        ));
    }

    /**
     * A single article with its rendered HTML body, or null if not found.
     */
    public function findOneBySlug(string $slug): ?Article
    {
        // Reject anything that could escape the articles directory.
        if (!preg_match(self::SLUG_PATTERN, $slug)) {
            return null;
        }

        $file = $this->articlesDir.'/'.$slug.'.md';
        if (!is_file($file)) {
            return null;
        }

        return $this->hydrate($file, withContent: true);
    }

    /**
     * @return array<string, Article>
     */
    private function loadIndex(): array
    {
        if (null !== $this->index) {
            return $this->index;
        }

        $this->index = [];
        foreach (glob($this->articlesDir.'/*.md') ?: [] as $file) {
            // Keep the listing consistent with findOneBySlug(): a file whose
            // name isn't a valid slug would be listed but always 404.
            if (!preg_match(self::SLUG_PATTERN, basename($file, '.md'))) {
                $this->logger->warning('Skipping article with invalid slug filename: {file}', ['file' => $file]);
                continue;
            }

            $article = $this->hydrate($file, withContent: false);
            if (null !== $article) {
                $this->index[$article->slug] = $article;
            }
        }

        return $this->index;
    }

    private function hydrate(string $file, bool $withContent): ?Article
    {
        $raw = file_get_contents($file);
        if (false === $raw) {
            return null;
        }

        // One malformed front matter block must not take down the whole blog.
        try {
            $parsed = $this->frontMatterParser()->parse($raw);
        } catch (InvalidFrontMatterException $e) {
            $this->logger->error('Invalid front matter in {file}: {message}', ['file' => $file, 'message' => $e->getMessage()]);

            return null;
        }

        /** @var array<string, mixed> $meta */
        $meta = $parsed->getFrontMatter() ?? [];
        $body = $parsed->getContent();

        $slug = basename($file, '.md');

        /** @var list<string> $tags */
        $tags = array_values(array_map('strval', (array) ($meta['tags'] ?? [])));

        return new Article(
            slug: $slug,
            title: (string) ($meta['title'] ?? ucfirst(str_replace('-', ' ', $slug))),
            description: (string) ($meta['description'] ?? ''),
            author: (string) ($meta['author'] ?? 'Dispatch Team'),
            role: (string) ($meta['role'] ?? ''),
            date: $this->parseDate($meta['date'] ?? null, $file),
            category: (string) ($meta['category'] ?? 'General'),
            tags: $tags,
            readingTime: $this->readingTime($body),
            featured: (bool) ($meta['featured'] ?? false),
            content: $withContent ? $this->converter()->convert($body)->getContent() : null,
        );
    }

    /**
     * Symfony YAML turns an unquoted `date: 2026-10-06` into a Unix timestamp
     * (int), which `new \DateTimeImmutable((string) $int)` cannot parse. Handle
     * every shape the parser can return, and fall back to the file's mtime
     * (stable per deploy) rather than "now" (changes on every request).
     */
    private function parseDate(mixed $value, string $file): \DateTimeImmutable
    {
        try {
            return match (true) {
                $value instanceof \DateTimeInterface => \DateTimeImmutable::createFromInterface($value),
                \is_int($value) => new \DateTimeImmutable('@'.$value),
                \is_string($value) && '' !== $value => new \DateTimeImmutable($value),
                default => throw new \InvalidArgumentException('missing date'),
            };
        } catch (\Exception $e) {
            $this->logger->warning('Bad or missing date in {file}, using file mtime: {message}', ['file' => $file, 'message' => $e->getMessage()]);

            return new \DateTimeImmutable('@'.(filemtime($file) ?: time()));
        }
    }

    /**
     * Estimated reading time in whole minutes (200 words/minute, minimum 1).
     */
    private function readingTime(string $markdown): int
    {
        $words = str_word_count(strip_tags($markdown));

        return max(1, intdiv($words + self::WORDS_PER_MINUTE - 1, self::WORDS_PER_MINUTE));
    }

    private function converter(): MarkdownConverter
    {
        if (null === $this->converter) {
            $environment = new Environment([
                // Block `javascript:` and similar link targets. Raw HTML stays
                // allowed so authors can embed video; switch html_input to
                // 'escape' if articles never need it.
                'allow_unsafe_links' => false,
            ]);
            $environment->addExtension(new CommonMarkCoreExtension());
            $environment->addExtension(new GithubFlavoredMarkdownExtension());

            $this->converter = new MarkdownConverter($environment);
        }

        return $this->converter;
    }

    private function frontMatterParser(): FrontMatterParser
    {
        return $this->frontMatter ??= new FrontMatterParser(new SymfonyYamlFrontMatterParser());
    }
}
