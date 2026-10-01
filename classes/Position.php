<?php

class Position
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function getAll(): array
    {
        $sql = "
            SELECT
                id,
                code,
                title,
                description,
                status,
                created_at
            FROM positions
            ORDER BY title ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create(array $data): bool
    {
        $sql = "
            INSERT INTO positions (
                code,
                title,
                description,
                status
            )
            VALUES (
                :code,
                :title,
                :description,
                :status
            )
        ";

        $stmt = $this->pdo->prepare($sql);

        return $stmt->execute([
            'code' => $data['code'],
            'title' => $data['title'],
            'description' => $data['description'] ?: null,
            'status' => $data['status']
        ]);
    }
}
