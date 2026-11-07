<?php
/**
 * Hash Table DSA — HRIS
 *
 * Applied in:
 *   - Department and position lookup cache
 *     Instead of querying the DB or looping through
 *     arrays every time we need a department name or
 *     position title for an employee, we hash the ID
 *     once and retrieve it in O(1).
 *
 * Uses separate chaining to handle collisions
 * (each bucket holds a linked list of entries).
 *
 * Time complexity (average):
 *   put()    O(1)
 *   get()    O(1)
 *   remove() O(1)
 *   has()    O(1)
 *
 * Time complexity (worst case — all keys collide):
 *   All above O(n)
 *
 * Space complexity: O(n)
 */

class HashTable
{
    private array $buckets;   // array of chains (linked lists as arrays)
    private int   $capacity;  // number of buckets
    private int   $size = 0;  // total entries stored

    /**
     * @param int $capacity Number of buckets.
     *                      Larger = fewer collisions but more memory.
     *                      Prime numbers reduce clustering.
     */
    public function __construct(int $capacity = 64)
    {
        $this->capacity = $capacity;
        $this->buckets  = array_fill(0, $capacity, []);
    }

    /**
     * Hash function — converts a string key into a bucket index.
     * Uses polynomial rolling hash.
     * Time complexity: O(k) where k = key length
     *
     * @param  string $key
     * @return int    Bucket index 0 to capacity-1
     */
    private function hash(string $key): int
    {
        $hash  = 0;
        $prime = 31;
        $len   = strlen($key);

        for ($i = 0; $i < $len; $i++) {
            // ord() gives ASCII value of character
            $hash = ($hash * $prime + ord($key[$i])) % $this->capacity;
        }

        return abs($hash);
    }

    /**
     * Store a key-value pair in the hash table.
     * If the key already exists, its value is updated.
     * Time complexity: O(1) average
     *
     * @param string $key
     * @param mixed  $value
     */
    public function put(string $key, mixed $value): void
    {
        $index = $this->hash($key);

        // Check if key already exists in this bucket (update)
        foreach ($this->buckets[$index] as &$entry) {
            if ($entry['key'] === $key) {
                $entry['value'] = $value;
                return;
            }
        }
        unset($entry);

        // Key not found — add new entry to bucket chain
        $this->buckets[$index][] = ['key' => $key, 'value' => $value];
        $this->size++;
    }

    /**
     * Retrieve a value by key.
     * Returns null if the key does not exist.
     * Time complexity: O(1) average
     *
     * @param  string     $key
     * @return mixed|null
     */
    public function get(string $key): mixed
    {
        $index = $this->hash($key);

        foreach ($this->buckets[$index] as $entry) {
            if ($entry['key'] === $key) {
                return $entry['value'];
            }
        }

        return null;
    }

    /**
     * Check whether a key exists in the table.
     * Time complexity: O(1) average
     *
     * @param  string $key
     * @return bool
     */
    public function has(string $key): bool
    {
        $index = $this->hash($key);

        foreach ($this->buckets[$index] as $entry) {
            if ($entry['key'] === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * Remove a key-value pair from the table.
     * Time complexity: O(1) average
     *
     * @param  string $key
     * @return bool   true if removed, false if not found
     */
    public function remove(string $key): bool
    {
        $index = $this->hash($key);

        foreach ($this->buckets[$index] as $i => $entry) {
            if ($entry['key'] === $key) {
                array_splice($this->buckets[$index], $i, 1);
                $this->size--;
                return true;
            }
        }

        return false;
    }

    /**
     * Return all stored key-value pairs as a flat array.
     * Time complexity: O(n)
     *
     * @return array [ ['key' => ..., 'value' => ...], ... ]
     */
    public function entries(): array
    {
        $result = [];

        foreach ($this->buckets as $bucket) {
            foreach ($bucket as $entry) {
                $result[] = $entry;
            }
        }

        return $result;
    }

    /**
     * Return all keys stored in the table.
     *
     * @return string[]
     */
    public function keys(): array
    {
        return array_column($this->entries(), 'key');
    }

    /**
     * Return all values stored in the table.
     *
     * @return array
     */
    public function values(): array
    {
        return array_column($this->entries(), 'value');
    }

    /**
     * Return the number of entries stored.
     */
    public function size(): int
    {
        return $this->size;
    }

    /**
     * Check whether the table is empty.
     */
    public function isEmpty(): bool
    {
        return $this->size === 0;
    }

    /**
     * Clear all entries.
     */
    public function clear(): void
    {
        $this->buckets = array_fill(0, $this->capacity, []);
        $this->size    = 0;
    }

    /**
     * Debug — show bucket load distribution.
     * Useful for checking collision density.
     *
     * @return array [ bucket_index => count ]
     */
    public function debugLoad(): array
    {
        $load = [];
        foreach ($this->buckets as $i => $bucket) {
            if (!empty($bucket)) {
                $load[$i] = count($bucket);
            }
        }
        return $load;
    }
}