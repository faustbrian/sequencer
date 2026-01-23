<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Exceptions\OperationFailedException;
use Cline\Sequencer\Exceptions\VerboseOperationException;

describe('VerboseOperationException', function (): void {
    describe('Happy Path - Exception Creation', function (): void {
        test('creates exception with create factory method', function (): void {
            $exception = VerboseOperationException::create();

            expect($exception)->toBeInstanceOf(VerboseOperationException::class);
        });

        test('exception message matches expected text exactly', function (): void {
            $exception = VerboseOperationException::create();

            expect($exception->getMessage())->toBe('Verbose exception test');
        });

        test('exception is instance of OperationFailedException', function (): void {
            $exception = VerboseOperationException::create();

            expect($exception)->toBeInstanceOf(OperationFailedException::class);
        });
    });
});
