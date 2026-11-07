<?php


class BSTNode
{
    public string   $key;   // employee_id string e.g. "EMP-001"
    public mixed    $data;  // full employee row
    public ?BSTNode $left  = null;
    public ?BSTNode $right = null;

    public function __construct(string $key, mixed $data)
    {
        $this->key  = $key;
        $this->data = $data;
    }
}

class BinarySearchTree
{
    private ?BSTNode $root = null;
    private int      $size = 0;

    /**
     * Insert an employee into the BST.
     * Key is the employee_id string (e.g. "EMP-001").
     * Duplicate keys update the existing node's data.
     * Time complexity: O(log n) average
     *
     * @param string $key  employee_id string
     * @param mixed  $data full employee row
     */
    public function insert(string $key, mixed $data): void
    {
        $this->root = $this->insertNode($this->root, $key, $data);
    }

    private function insertNode(?BSTNode $node, string $key, mixed $data): BSTNode
    {
        // Empty spot — place the new node here
        if ($node === null) {
            $this->size++;
            return new BSTNode($key, $data);
        }

        if ($key < $node->key) {
            // Go left — key is smaller
            $node->left  = $this->insertNode($node->left,  $key, $data);
        } elseif ($key > $node->key) {
            // Go right — key is larger
            $node->right = $this->insertNode($node->right, $key, $data);
        } else {
            // Duplicate key — update data in place
            $node->data = $data;
        }

        return $node;
    }

    /**
     * Search for an employee by employee_id string.
     * Returns the employee row array or null if not found.
     * Time complexity: O(log n) average
     *
     * @param  string     $key  employee_id e.g. "EMP-001"
     * @return mixed|null       Employee row or null
     */
    public function search(string $key): mixed
    {
        $node = $this->searchNode($this->root, $key);
        return $node?->data;
    }

    private function searchNode(?BSTNode $node, string $key): ?BSTNode
    {
        // Base case — not found or exact match
        if ($node === null || $node->key === $key) {
            return $node;
        }

        if ($key < $node->key) {
            return $this->searchNode($node->left,  $key);
        }

        return $this->searchNode($node->right, $key);
    }

    /**
     * Check whether a key exists in the BST.
     *
     * @param  string $key
     * @return bool
     */
    public function has(string $key): bool
    {
        return $this->searchNode($this->root, $key) !== null;
    }

    /**
     * Delete a node by key.
     * Time complexity: O(log n) average
     *
     * @param  string $key
     */
    public function delete(string $key): void
    {
        $this->root = $this->deleteNode($this->root, $key);
    }

    private function deleteNode(
        ?BSTNode $node,
        string $key,
        bool $decreaseSize = true
    ): ?BSTNode {
        if ($node === null) {
            return null;
        }

        if ($key < $node->key) {
            $node->left  = $this->deleteNode($node->left,  $key);
        } elseif ($key > $node->key) {
            $node->right = $this->deleteNode($node->right, $key);
        } else {
            // Node to delete found
            if ($decreaseSize) {
                $this->size--;
            }

            if ($node->left === null) {
                return $node->right; // replace with right child
            }
            if ($node->right === null) {
                return $node->left;  // replace with left child
            }

            // Node has two children —
            // replace with in-order successor (smallest in right subtree)
            $successor   = $this->findMin($node->right);

            $node->key   = $successor->key;
            $node->data  = $successor->data;

            $node->right = $this->deleteNode(
                $node->right,
                $successor->key,
                false
            );
        }

        return $node;
    }

    private function findMin(BSTNode $node): BSTNode
    {
        while ($node->left !== null) {
            $node = $node->left;
        }
        return $node;
    }

    /**
     * In-order traversal — returns all employees sorted
     * by employee_id ascending (EMP-001, EMP-002 ...).
     * Time complexity: O(n)
     *
     * @return array
     */
    public function inOrder(): array
    {
        $result = [];
        $this->inOrderTraverse($this->root, $result);
        return $result;
    }

    private function inOrderTraverse(?BSTNode $node, array &$result): void
    {
        if ($node === null) return;
        $this->inOrderTraverse($node->left,  $result);
        $result[] = $node->data;
        $this->inOrderTraverse($node->right, $result);
    }

    /**
     * Return the number of nodes in the tree.
     */
    public function size(): int
    {
        return $this->size;
    }

    /**
     * Check whether the tree is empty.
     */
    public function isEmpty(): bool
    {
        return $this->root === null;
    }
}
/**
 * Binary Search Tree DSA — HRIS
 *
 * Applied in:
 *   - Employee lookup by employee ID (emp_code e.g. EMP-001)
 *     All employees are inserted into the BST once.
 *     Search by employee ID runs in O(log n) average
 *     instead of O(n) linear scan.
 *
 * Time complexity (average):
 *   insert() O(log n)
 *   search() O(log n)
 *   delete() O(log n)
 *
 * Time complexity (worst case — skewed tree):
 *   All above O(n)
 *
 * Space complexity: O(n)
 */
