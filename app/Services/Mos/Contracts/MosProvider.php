<?php

namespace App\Services\Mos\Contracts;

interface MosProvider
{
    public function key(): string;

    public function domain(): string;

    public function label(): string;

    /**
     * @return array<int, string>
     */
    public function capabilities(): array;

    public function priority(): int;

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array;
}
