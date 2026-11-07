<?php

/**
 * Queue (FIFO - First In, First Out)
 *
 * Time complexity:
 * enqueue() O(1) amortized
 * dequeue() O(1)
 * front()   O(1)
 * back()    O(1)
 * isEmpty() O(1)
 * size()    O(1)
 */
class Queue
{
    private array $items = [];
    private int $head = 0;
    private int $tail = 0;

    public function enqueue(mixed $item): void
    {
        $this->items[$this->tail] = $item;
        $this->tail++;
    }

    public function dequeue(): mixed
    {
        if ($this->isEmpty()) {
            return null;
        }

        $item = $this->items[$this->head];

        unset($this->items[$this->head]);

        $this->head++;

        return $item;
    }

    public function front(): mixed
    {
        return $this->isEmpty()
            ? null
            : $this->items[$this->head];
    }

    public function back(): mixed
    {
        return $this->isEmpty()
            ? null
            : $this->items[$this->tail - 1];
    }

    public function isEmpty(): bool
    {
        return $this->head === $this->tail;
    }

    public function size(): int
    {
        return $this->tail - $this->head;
    }

    public function toArray(): array
    {
        return array_values(
            array_slice(
                $this->items,
                $this->head,
                $this->size(),
                true
            )
        );
    }

    public function clear(): void
    {
        $this->items = [];
        $this->head = 0;
        $this->tail = 0;
    }

}