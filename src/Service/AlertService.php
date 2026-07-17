<?php

namespace App\Service;

use App\Repository\AlertRepository;
use App\Repository\PriceRepository;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;

class AlertService
{
    private AlertRepository $alertRepo;
    private PriceRepository $priceRepo;

    public function __construct()
    {
        $this->alertRepo = new AlertRepository();
        $this->priceRepo = new PriceRepository();
    }

    public function checkAndSend(): int
    {
        $alerts = $this->alertRepo->getActiveAlerts();
        $sent   = 0;

        foreach ($alerts as $alert) {
            $bestPrice = $this->getBestPriceForAlert($alert);
            if ($bestPrice !== null && $bestPrice <= $alert['target_price']) {
                $this->sendAlertEmail($alert, $bestPrice);
                $this->alertRepo->markSent((int)$alert['id']);
                $sent++;
            }
        }

        return $sent;
    }

    private function getBestPriceForAlert(array $alert): ?float
    {
        $fuelTypeId = (int)$alert['fuel_type_id'];

        if (!empty($alert['city'])) {
            $db   = \App\Database::getInstance();
            $stmt = $db->prepare(
                'SELECT MIN(p.price)
                 FROM prices p
                 JOIN stations s ON s.id = p.station_id
                 WHERE p.fuel_type_id = :fuel
                   AND p.price_date = CURDATE()
                   AND s.city = :city'
            );
            $stmt->execute([':fuel' => $fuelTypeId, ':city' => $alert['city']]);
        } else {
            $db   = \App\Database::getInstance();
            $stmt = $db->prepare(
                'SELECT MIN(price) FROM prices WHERE fuel_type_id = :fuel AND price_date = CURDATE()'
            );
            $stmt->execute([':fuel' => $fuelTypeId]);
        }

        $val = $stmt->fetchColumn();
        return $val !== false ? (float)$val : null;
    }

    private function sendAlertEmail(array $alert, float $bestPrice): void
    {
        $appUrl       = $_ENV['APP_URL'] ?? 'https://kuras.pricer.lt';
        $fromAddress  = $_ENV['MAIL_FROM'] ?? 'noreply@kuras.pricer.lt';
        $fromName     = $_ENV['MAIL_FROM_NAME'] ?? 'Kuras Pricer';
        $mailDsn      = $_ENV['MAIL_DSN'] ?? 'smtp://localhost:1025';

        $cityLabel = $alert['city'] ? " ({$alert['city']})" : ' (visa Lietuva)';
        $unsubscribeUrl = "$appUrl/alerts/unsubscribe?token={$alert['token']}";

        $subject = "🔔 Kuro kaina pasiekė jūsų tikslą: {$alert['fuel_name']}{$cityLabel}";
        $body = "Sveiki!\n\n"
            . "Geriausia {$alert['fuel_name']} kaina{$cityLabel} šiandien: {$bestPrice} €/L\n"
            . "Jūsų nustatyta tikslinė kaina: {$alert['target_price']} €/L\n\n"
            . "Peržiūrėkite geriausias kainas: $appUrl\n\n"
            . "Atsisakyti pranešimų: $unsubscribeUrl";

        try {
            $transport = Transport::fromDsn($mailDsn);
            $mailer    = new Mailer($transport);
            $email = (new Email())
                ->from("$fromName <$fromAddress>")
                ->to($alert['email'])
                ->subject($subject)
                ->text($body);
            $mailer->send($email);
        } catch (\Throwable $e) {
            error_log('[AlertService] Failed to send email to ' . $alert['email'] . ': ' . $e->getMessage());
        }
    }

    public function createAlert(string $email, int $fuelTypeId, ?string $city, float $targetPrice): string
    {
        $token = bin2hex(random_bytes(32));
        $this->alertRepo->create($email, $fuelTypeId, $city, $targetPrice, $token);

        // Send confirmation email
        $this->sendConfirmationEmail($email, $token, $fuelTypeId, $city, $targetPrice);

        return $token;
    }

    private function sendConfirmationEmail(string $email, string $token, int $fuelTypeId, ?string $city, float $targetPrice): void
    {
        $ft = $this->priceRepo->getFuelTypeBySlug('');
        // Get fuel name
        $db   = \App\Database::getInstance();
        $stmt = $db->prepare('SELECT name FROM fuel_types WHERE id = :id');
        $stmt->execute([':id' => $fuelTypeId]);
        $fuelName = $stmt->fetchColumn() ?: 'kuras';

        $appUrl      = $_ENV['APP_URL'] ?? 'https://kuras.pricer.lt';
        $fromAddress = $_ENV['MAIL_FROM'] ?? 'noreply@kuras.pricer.lt';
        $fromName    = $_ENV['MAIL_FROM_NAME'] ?? 'Kuras Pricer';
        $mailDsn     = $_ENV['MAIL_DSN'] ?? 'smtp://localhost:1025';
        $cityLabel   = $city ?: 'visa Lietuva';
        $unsubscribeUrl = "$appUrl/alerts/unsubscribe?token=$token";

        $body = "Sveiki!\n\n"
            . "Jūsų kainų signalas sukurtas:\n"
            . "Kuras: $fuelName\n"
            . "Regionas: $cityLabel\n"
            . "Tikslinė kaina: $targetPrice €/L\n\n"
            . "Gausite pranešimą, kai kaina pasieks arba nukris žemiau nustatytos ribos.\n\n"
            . "Atsisakyti pranešimų: $unsubscribeUrl";

        try {
            $transport = Transport::fromDsn($mailDsn);
            $mailer    = new Mailer($transport);
            $email_obj = (new Email())
                ->from("$fromName <$fromAddress>")
                ->to($email)
                ->subject('Kainų signalas sukurtas — Kuras Pricer')
                ->text($body);
            $mailer->send($email_obj);
        } catch (\Throwable $e) {
            error_log('[AlertService] Confirmation email failed: ' . $e->getMessage());
        }
    }
}
