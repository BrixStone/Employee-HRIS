<?php


class ListNode
{
    public mixed    $data;
    public ?ListNode $prev = null;
    public ?ListNode $next = null;

    public function __construct(mixed $data)
    {
        $this->data = $data;
    }
}

class LinkedList
{
    private ?ListNode $head = null;
    private ?ListNode $tail = null;
    private int       $size = 0;

    /**
     * Append a node to the TAIL (end) of the list.
     * Used when adding periods in chronological order.
     *
     * @param mixed $data
     */
    public function append(mixed $data): void
    {
        $node = new ListNode($data);

        if ($this->head === null) {
            // First node — head and tail are the same
            $this->head = $node;
            $this->tail = $node;
        } else {
            // Link new node after current tail
            $node->prev       = $this->tail;
            $this->tail->next = $node;
            $this->tail       = $node;
        }

        $this->size++;
    }

    /**
     * Prepend a node to the HEAD (front) of the list.
     *
     * @param mixed $data
     */
    public function prepend(mixed $data): void
    {
        $node = new ListNode($data);

        if ($this->head === null) {
            $this->head = $node;
            $this->tail = $node;
        } else {
            $node->next       = $this->head;
            $this->head->prev = $node;
            $this->head       = $node;
        }

        $this->size++;
    }

    /**
     * Find the first node where $data[$key] === $value.
     * Returns the ListNode or null if not found.
     * Time complexity: O(n)
     *
     * @param  string $key
     * @param  mixed  $value
     * @return ListNode|null
     */
    public function find(string $key, mixed $value): ?ListNode
    {
        $current = $this->head;

        while ($current !== null) {
            $data = $current->data;

            if (is_array($data) && isset($data[$key]) && (string)$data[$key] === (string)$value) {
                return $current;
            }

            $current = $current->next;
        }

        return null;
    }

    /**
     * Get the node AFTER the node matching $key => $value.
     * Useful for "next payroll period".
     *
     * @param  string $key
     * @param  mixed  $value
     * @return mixed|null  The data of the next node
     */
    public function getNext(string $key, mixed $value): mixed
    {
        $node = $this->find($key, $value);

        if ($node === null || $node->next === null) {
            return null;
        }

        return $node->next->data;
    }

    /**
     * Get the node BEFORE the node matching $key => $value.
     * Useful for "previous payroll period".
     *
     * @param  string $key
     * @param  mixed  $value
     * @return mixed|null  The data of the previous node
     */
    public function getPrev(string $key, mixed $value): mixed
    {
        $node = $this->find($key, $value);

        if ($node === null || $node->prev === null) {
            return null;
        }

        return $node->prev->data;
    }

    /**
     * Delete the first node where $data[$key] === $value.
     * Time complexity: O(n)
     *
     * @param  string $key
     * @param  mixed  $value
     * @return bool   true if deleted, false if not found
     */
    public function delete(string $key, mixed $value): bool
    {
        $node = $this->find($key, $value);

        if ($node === null) {
            return false;
        }

        // Re-link neighbours
        if ($node->prev !== null) {
            $node->prev->next = $node->next;
        } else {
            // Deleting head
            $this->head = $node->next;
        }

        if ($node->next !== null) {
            $node->next->prev = $node->prev;
        } else {
            // Deleting tail
            $this->tail = $node->prev;
        }

        $this->size--;
        return true;
    }

    /**
     * Traverse forward (head → tail) and return all data.
     * Oldest period first.
     *
     * @return array
     */
    public function toArray(): array
    {
        $result  = [];
        $current = $this->head;

        while ($current !== null) {
            $result[] = $current->data;
            $current  = $current->next;
        }

        return $result;
    }

    /**
     * Traverse backward (tail → head) and return all data.
     * Newest period first.
     *
     * @return array
     */
    public function toArrayReversed(): array
    {
        $result  = [];
        $current = $this->tail;

        while ($current !== null) {
            $result[] = $current->data;
            $current  = $current->prev;
        }

        return $result;
    }

    /**
     * Return the head (oldest period) data.
     *
     * @return mixed|null
     */
    public function head(): mixed
    {
        return $this->head?->data;
    }

    /**
     * Return the tail (newest/latest period) data.
     *
     * @return mixed|null
     */
    public function tail(): mixed
    {
        return $this->tail?->data;
    }

    /**
     * Return the number of nodes in the list.
     */
    public function size(): int
    {
        return $this->size;
    }

    /**
     * Check whether the list is empty.
     */
    public function isEmpty(): bool
    {
        return $this->size === 0;
    }
}

/**
 * Linked List DSA — HRIS
 * Doubly Linked List (each node points forward AND backward).
 *
 * Applied in:
 *   - Payroll periods chain
 *     Each payroll period is a node. You can traverse
 *     forward (oldest → newest) or backward (newest → oldest),
 *     and get the previous/next period from any given period.
 *
 * Time complexity:
 *   append()     O(1)  — add to tail
 *   prepend()    O(1)  — add to head
 *   find()       O(n)  — search by value
 *   delete()     O(n)  — find then unlink
 *   toArray()    O(n)  — traverse all nodes
 */