<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Exceptions\OperationFailedException;
use Cline\Sequencer\Exceptions\OperationFailedWithCodeException;

describe('OperationFailedWithCodeException', function (): void {
    describe('Happy Path - Exception Creation', function (): void {
        test('creates exception with create factory method', function (): void {
            $exception = OperationFailedWithCodeException::create('Test failure', 500);

            expect($exception)->toBeInstanceOf(OperationFailedWithCodeException::class);
        });

        test('exception message matches provided message', function (): void {
            $exception = OperationFailedWithCodeException::create('Custom error message', 404);

            expect($exception->getMessage())->toBe('Custom error message');
        });

        test('exception code matches provided code', function (): void {
            $exception = OperationFailedWithCodeException::create('Test failure', 500);

            expect($exception->getCode())->toBe(500);
        });

        test('exception is instance of OperationFailedException', function (): void {
            $exception = OperationFailedWithCodeException::create('Test failure', 500);

            expect($exception)->toBeInstanceOf(OperationFailedException::class);
        });
    });

    describe('Edge Cases - Various Error Codes', function (): void {
        test('handles validation error code 400', function (): void {
            $exception = OperationFailedWithCodeException::create('Validation failed', 400);

            expect($exception->getCode())->toBe(400);
        });

        test('handles conflict error code 409', function (): void {
            $exception = OperationFailedWithCodeException::create('Resource conflict', 409);

            expect($exception->getCode())->toBe(409);
        });

        test('handles server error code 500', function (): void {
            $exception = OperationFailedWithCodeException::create('Server error', 500);

            expect($exception->getCode())->toBe(500);
        });
    });
});
