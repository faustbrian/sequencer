<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Commands;

use Cline\Sequencer\Support\DeferredOperationProcessor;
use Illuminate\Console\Command;
use Override;

use function sprintf;

/**
 * @author Brian Faust <brian@cline.sh>
 */
final class ProcessDeferredCommand extends Command
{
    /** @var string */
    #[Override()]
    protected $signature = 'sequencer:deferred-process
                            {--limit=100 : Maximum number of deferred operations to process}';

    /** @var string */
    #[Override()]
    protected $description = 'Process due deferred operations';

    public function __construct(
        private readonly DeferredOperationProcessor $processor,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $stats = $this->processor->processDue($limit);

        $this->components->info(sprintf('Processed %d deferred operation(s).', $stats['processed']));

        return self::SUCCESS;
    }
}
