<?php

declare(strict_types=1);

namespace App\Blog;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\FrontMatter\Data\SymfonyYamlFrontMatterParser;
use League\CommonMark\Extension\FrontMatter\FrontMatterParser;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reads blog articles from markdown files committed in the git repository.
 *
 * Each `*.md` file under the articles directory carries YAML front matter for
 * its metadata; the filename (minus extension) becomes the URL slug. Metadata
 * is parsed lazily and memoized; the markdown body is only rendered to HTML
 * when a single article is requested for its detail page.
 *
 * Articles marked `draft: true`, and articles whose `date` is in the future
 * (scheduled posts), are hidden: they are left out of every listing and
 * cannot be fetched by slug until they are published.
 */
final class ArticleRepository
{
    private const WORDS_PER_MINUTE = 200;

    private ?MarkdownConverter $converter = null;
    private ?FrontMatterParser $frontMatter = null;

    /** @var array<string, Article>|null memoized metadata, keyed by slug */
    private ?array $index = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/content/articles')]
        private readonly string $articlesDir,
    ) {
    }

    /**
     * All articles, most recent first.
     *
     * @return list<Article>
     */
    public function findAll(): array
    {
        $articles = array_values($this->loadIndex());

        usort($articles, static fn (Article $a, Article $b): int => $b->date <=> $a->date);

        return $articles;
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
        if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
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

        $parsed = $this->frontMatterParser()->parse($raw);
        /** @var array<string, mixed> $meta */
        $meta = $parsed->getFrontMatter() ?? [];
        $body = $parsed->getContent();

        $slug = basename($file, '.md');

        $date = $this->parseDate($meta['date'] ?? null);

        // Drafts and scheduled (future-dated) articles are not visible yet.
        if ($this->isDraft($meta) || $date > new \DateTimeImmutable('now')) {
            return null;
        }

        /** @var list<string> $tags */
        $tags = array_values(array_map('strval', (array) ($meta['tags'] ?? [])));

        return new Article(
            slug: $slug,
            title: (string) ($meta['title'] ?? ucfirst(str_replace('-', ' ', $slug))),
            description: (string) ($meta['description'] ?? ''),
            author: (string) ($meta['author'] ?? 'Dispatch Team'),
            role: (string) ($meta['role'] ?? ''),
            date: $date,
            category: (string) ($meta['category'] ?? 'General'),
            tags: $tags,
            readingTime: $this->readingTime($body),
            featured: (bool) ($meta['featured'] ?? false),
            content: $withContent ? $this->converter()->convert($body)->getContent() : null,
        );
    }

    /**
     * Parses the front matter `date`, whether quoted (a string) or unquoted
     * (Symfony YAML turns unquoted dates into an int Unix timestamp).
     */
    private function parseDate(mixed $raw): \DateTimeImmutable
    {
        if (\is_int($raw)) {
            return (new \DateTimeImmutable('@'.$raw))
                ->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        }

        if ($raw instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($raw);
        }

        if (\is_string($raw) && '' !== trim($raw)) {
            try {
                return new \DateTimeImmutable($raw);
            } catch (\Exception) {
                // Fall through to "now" for unparseable values.
            }
        }

        return new \DateTimeImmutable();
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function isDraft(array $meta): bool
    {
        return filter_var($meta['draft'] ?? false, \FILTER_VALIDATE_BOOLEAN);
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
            $environment = new Environment();
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
