<?php
defined('APP') or die('Direkte adgang ikke tilladt');

require_once APP_ROOT . '/phpmailer/src/Exception.php';
require_once APP_ROOT . '/phpmailer/src/PHPMailer.php';
require_once APP_ROOT . '/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Sender adgangsmails til nye/nulstillede brugere via PHPMailer+SMTP
 * (se config.php: config['mail']). Sendes ALDRIG automatisk - kun naar en
 * admin trykker "Send adgangsmail" på brugere.php, saa et 1. gangs-kodeord
 * ikke sendes ud af en fejl.
 */
class Mailer
{
    /** @return string|null Fejlbesked, eller null hvis mailen blev sendt. */
    public static function sendAccessMail(string $email, string $navn, string $kodeord): ?string
    {
        $cfg = $GLOBALS['config']['mail'] ?? null;
        if (!$cfg || empty($cfg['host'])) {
            return 'Mail er ikke sat op endnu (mangler config["mail"] i config.php).';
        }

        $mail = new PHPMailer(true);
        try {
            // Uafhængig af config['debug'] (som ogsaa slaar PHP-fejl til i browseren) - saa
            // en admin kan faa den fulde SMTP-samtale i error_log ved fejlsøgning i produktion
            // uden at eksponere PHP-fejl for almindelige brugere. Se README/config.example.php.
            if (!empty($GLOBALS['config']['mail_debug'])) {
                $mail->SMTPDebug   = SMTP::DEBUG_SERVER;
                $mail->Debugoutput = 'error_log';
            }
            $mail->CharSet = 'UTF-8';

            $mail->isSMTP();
            $mail->Host       = $cfg['host'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $cfg['username'];
            $mail->Password   = $cfg['password'];
            $mail->SMTPSecure = $cfg['smtp_secure'] ?? PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = (int)($cfg['port'] ?? 587);

            $mail->setFrom($cfg['from_email'], $cfg['from_name'] ?? 'Equilive');
            $mail->addAddress($email, $navn);

            $mail->isHTML(false);
            $mail->Subject = 'Din adgang til Equilive';
            $mail->Body    = self::accessMailBody($navn, $email, $kodeord);

            $mail->send();
            return null;
        } catch (PHPMailerException $e) {
            return 'Kunne ikke sende mail: ' . $mail->ErrorInfo;
        }
    }

    private static function accessMailBody(string $navn, string $email, string $kodeord): string
    {
        $appUrl = $GLOBALS['config']['app_url'] ?? url('/');

        return "Hej $navn,\n\n"
            . "Jeg har oprettet en adgang til dig, og du tilgår applikationen via $appUrl\n\n"
            . "Bruger-id: $email\n"
            . "Kodeord: $kodeord\n\n"
            . "Du bliver bedt om at ændre kodeord ved 1. logon.\n\n"
            . "Vær opmærksom på, at kvaliteten i data afhænger af, at stævnearrangøren får tilknyttet "
            . "officials korrekt i Equipe - EquiLive beriger ikke data, vi viser dem, som de er "
            . "registreret i Equipe og hos Dansk Ride Forbund. Er registreringen mangelfuld, slår det "
            . "igennem her.";
    }
}
