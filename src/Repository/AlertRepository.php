<?php

namespace App\Repository;

use App\Database;

class AlertRepository
{
    private \PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function create(string $email, int $fuelTypeId, ?string $city, float $targetPrice, string $token): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO alerts (email, fuel_type_id, city, target_price, token)
             VALUES (:email, :fuel_type_id, :city, :target_price, :token)'
        );
        $stmt->execute([
            ':email'        => $email,
            ':fuel_type_id' => $fuelTypeId,
            ':city'         => $city,
            ':target_price' => $targetPrice,
            ':token'        => $token,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function findByToken(string $token): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM alerts WHERE token = :token');
        $stmt->execute([':token' => $token]);
        return $stmt->fetch() ?: null;
    }

    public function deactivate(string $token): void
    {
        $stmt = $this->db->prepare('UPDATE alerts SET is_active = 0 WHERE token = :token');
        $stmt->execute([':token' => $token]);
    }

    public function getActiveAlerts(): array
    {
        return $this->db->query(
            'SELECT a.*, ft.slug AS fuel_slug, ft.name AS fuel_name
             FROM alerts a
             JOIN fuel_types ft ON ft.id = a.fuel_type_id
             WHERE a.is_active = 1
               AND (a.last_sent_at IS NULL OR DATE(a.last_sent_at) < CURDATE())'
        )->fetchAll();
    }

    public function markSent(int $id): void
    {
        $stmt = $this->db->prepare('UPDATE alerts SET last_sent_at = NOW() WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    public function countActive(): int
    {
        return (int)$this->db->query('SELECT COUNT(*) FROM alerts WHERE is_active = 1')->fetchColumn();
    }
}
