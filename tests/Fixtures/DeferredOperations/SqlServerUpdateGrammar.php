<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Fixtures\DeferredOperations;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Database\Query\Grammars\SqlServerGrammar;
use Illuminate\Support\Str;

/**
 * SQLite transports the test. SQL Server parameter compilation and bindings
 * are unchanged; only SELECT/savepoints and qualified assignment names differ.
 */
final class SqlServerUpdateGrammar extends SqlServerGrammar
{
    public function compileSelect(Builder $query): string
    {
        return new SQLiteGrammar($this->connection)->compileSelect($query);
    }

    public function compileSavepoint($name): string
    {
        return new SQLiteGrammar($this->connection)->compileSavepoint($name);
    }

    public function compileSavepointRollBack($name): string
    {
        return new SQLiteGrammar($this->connection)->compileSavepointRollBack($name);
    }

    protected function compileUpdateColumns(Builder $query, array $values): string
    {
        $unqualified = [];

        foreach ($values as $column => $value) {
            $unqualified[Str::afterLast($column, '.')] = $value;
        }

        return parent::compileUpdateColumns($query, $unqualified);
    }
}
