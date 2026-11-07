
<?php

class Stack
{
    private array $items = [];


    // PUSH
    // Adds a new item to the top of the stack.
    // Used when a leave request status changes.
    // Example: $history->push('Approved');

    public function push(mixed $item): void
    {
        $this->items[] = $item;
    }


    // POP
    // Removes and returns the latest item from the stack.
    // Returns null if the stack is empty.
    // Example: $history->pop();

    public function pop(): mixed
    {
        if ($this->isEmpty()) {
            return null;
        }

        return array_pop($this->items);
    }


    // PEEK
    // Gets the latest item without removing it.
    // Useful for checking the current or most recent status.
    // Example: $history->peek();

    public function peek(): mixed
    {
        if ($this->isEmpty()) {
            return null;
        }

        return end($this->items);
    }


    // ISEMPTY
    // Checks whether the stack contains any items.
    // Returns true if empty and false if it contains items.

    public function isEmpty(): bool
    {
        return empty($this->items);
    }


    // SIZE
    // Returns the total number of items stored in the stack.
    // Useful for counting leave request status changes.

    public function size(): int
    {
        return count($this->items);
    }


    // TOARRAY
    // Returns all stack items in their original order.
    // Displays history from the oldest entry to the newest entry.

    public function toArray(): array
    {
        return $this->items;
    }


    // TOARRAYREVERSED
    // Returns all stack items in reverse order.
    // Useful for displaying the newest leave status first.

    public function toArrayReversed(): array
    {
        return array_reverse($this->items);
    }


    // CLEAR
    // Removes all items from the stack.
    // Use when the stack needs to be reset.

    public function clear(): void
    {
        $this->items = [];
    }
}


    /*
     * HOW TO NAVIGATE AND USE THIS CLASS:
     *
     * This Stack class uses the LIFO (Last In, First Out)
     * data structure to manage leave request status history.
     *
     * APPLICATION:
     *
     * Leave Request Status History
     * - Every time an employee's leave request status changes,
     *   the new status is pushed onto the stack.
     * - HR can view the history of status changes.
     * - The latest status change can be accessed using peek().
     * - The latest item can be removed using pop().
     *
     * EXAMPLE STATUS HISTORY:
     * Pending → Approved → Cancelled
     *
     * TO USE THIS CLASS:
     * - Navigate to the HRIS project folder.
     * - Open the file responsible for processing leave requests.
     * - Include this Stack class using require_once.
     * - Create a Stack object.
     * - Use push(), pop(), peek(), and other methods
     *   to manage leave request history.
     *
     * Example:
     * require_once '../classes/Stack.php';
     *
     * $history = new Stack();
     * $history->push('Pending');
     * $history->push('Approved');
     *
     * echo $history->peek();
     *
     * The output will be: Approved
     */