<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Commands;

use Cline\Sequencer\Support\DeferredOperationProcessor;
use Cline\Sequencer\Support\DeferredProcessRuntime;
use Illuminate\Console\Command;
use Override;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function resolve;
use function sprintf;
use function throw_if;

/**
 * @author Brian Faust <brian@cline.sh>
 */
final class ProcessDeferredCommand extends Command
{
    /** @var string */
    #[Override()]
    protected $signature = 'sequencer:deferred-process
                            {--limit=100 : Maximum number of deferred operations to process}
                            {--operation=* : Only process these stored operation identifiers}';

    /** @var string */
    #[Override()]
    protected $description = 'Process due deferred operations';

    private bool $boundedInvocation = false;

    private bool $handling = false;

    public function __construct(
        private readonly DeferredOperationProcessor $processor,
    ) {
        parent::__construct();
    }

    #[Override()]
    public function run(InputInterface $input, OutputInterface $output): int
    {
        $runtime = resolve(DeferredProcessRuntime::class);

        if (!$runtime->enabled()) {
            return parent::run($input, $output);
        }

        // Nested Laravel calls bypass application command events, so the
        // concrete command owns admission rather than an application decorator.
        $runtime->enter($input);
        $this->boundedInvocation = true;

        try {
            return parent::run($input, $output);
        } finally {
            $this->boundedInvocation = false;
        }
    }

    public function handle(): int
    {
        throw_if(resolve(DeferredProcessRuntime::class)->enabled() && (!$this->boundedInvocation || $this->handling), RuntimeException::class, 'Run deferred processing through the external artisan CLI so its lifetime can be bounded.');

        $this->handling = true;

        try {
            return $this->processDeferred();
        } finally {
            $this->handling = false;
        }
    }

    private function processDeferred(): int
    {
        $limit = (int) $this->option('limit');

        /** @var list<string> $operations */
        $operations = $this->option('operation');
        $stats = $this->processor->processDue($limit, $operations === [] ? null : $operations);

        $this->components->info(sprintf('Processed %d deferred operation(s).', $stats['processed']));

        return self::SUCCESS;
    }
}
