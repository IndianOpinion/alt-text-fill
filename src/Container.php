<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill;

use IndianOpinion\AltTextFill\Adapter\AdapterRegistry;
use IndianOpinion\AltTextFill\Context\ContextBuilder;
use IndianOpinion\AltTextFill\Context\MediaLibrary;
use IndianOpinion\AltTextFill\Context\UsageFinder;
use IndianOpinion\AltTextFill\Context\WpMediaLibrary;
use IndianOpinion\AltTextFill\Image\ImagePreparer;
use IndianOpinion\AltTextFill\Image\RemoteFetcher;
use IndianOpinion\AltTextFill\Storage\Repository;
use IndianOpinion\AltTextFill\Storage\WpdbRepository;

/**
 * Builds the services once per request. Tests pass their own clock,
 * repository, library and logger.
 */
class Container
{
    public readonly Settings $settings;
    public readonly Clock $clock;
    public readonly State $state;
    public readonly Repository $repository;
    public readonly MediaLibrary $library;
    public readonly Logger $logger;
    public readonly AltField $field;
    public readonly EmptyAlt $emptyAlt;
    public readonly Switches $switches;
    public readonly AdapterRegistry $adapters;
    public readonly UsageFinder $finder;
    public readonly ContextBuilder $contexts;
    public readonly ImagePreparer $preparer;
    public readonly PromptBuilder $prompts;
    public readonly Validator $validator;
    public readonly Scanner $scanner;
    public readonly Writer $writer;
    public readonly Review $review;
    public readonly Undo $undo;
    public readonly Runner $runner;
    public readonly Status $status;

    public function __construct(
        ?Clock $clock = null,
        ?Repository $repository = null,
        ?MediaLibrary $library = null,
        ?Logger $logger = null,
        ?Settings $settings = null,
    ) {
        global $wpdb;
        $this->settings = $settings ?? new Settings();
        $this->clock = $clock ?? new SystemClock();
        $this->state = new State($this->clock, State::lockDatabase($wpdb));
        $this->repository = $repository ?? new WpdbRepository($wpdb);
        $this->library = $library ?? new WpMediaLibrary($wpdb);
        $this->logger = $logger ?? new Logger();
        $this->field = new AltField();
        $this->emptyAlt = new EmptyAlt($this->field, $this->settings);
        $this->switches = new Switches($this->state, $this->settings);
        $this->adapters = new AdapterRegistry($this->settings);
        $this->finder = new UsageFinder($this->library, $this->settings);
        $this->contexts = new ContextBuilder($this->settings);
        $this->preparer = new ImagePreparer($this->settings, new RemoteFetcher($this->settings->stringList('remote_hosts')), $this->logger);
        $this->prompts = new PromptBuilder($this->settings, $this->logger);
        $this->validator = new Validator($this->settings);
        $this->scanner = new Scanner($this->library, $this->finder, $this->emptyAlt, $this->field, $this->settings, $this->state, $this->repository, $this->adapters);
        $this->writer = new Writer($this->field, $this->repository, $this->clock);
        $this->review = new Review($this->repository, $this->field, $this->writer, $this->validator, $this->clock, $this->logger);
        $this->undo = new Undo($this->repository, $this->field, $this->writer);
        $this->runner = new Runner(
            $this->settings,
            $this->state,
            $this->clock,
            $this->scanner,
            $this->contexts,
            $this->preparer,
            $this->adapters,
            $this->prompts,
            $this->validator,
            $this->repository,
            $this->field,
            $this->logger,
            $this->switches
        );
        $this->status = new Status($this);
    }
}
