<?php
/**
 * Anydrop — Email OTP: provider registry
 *
 * Maps driver_key -> driver class (the only place that mapping lives —
 * plan §22's "no if/elseif chains in the OTP endpoints") and loads
 * providers from email_otp_providers, active-only, priority order,
 * with local daily/monthly quota already checked and secrets already
 * decrypted so EmailOtpService never touches SecretManager or the DB
 * row shape directly.
 */

require_once __DIR__ . '/SecretManager.php';
require_once __DIR__ . '/ResendProvider.php';
require_once __DIR__ . '/BrevoProvider.php';
require_once __DIR__ . '/MailerSendProvider.php';
require_once __DIR__ . '/SendixProvider.php';
require_once __DIR__ . '/MailerooProvider.php';
require_once __DIR__ . '/MailjetProvider.php';

class ProviderRegistry
{
    /** driver_key => fully-qualified class name. Add a 6th/7th provider by adding one line here. */
    private const DRIVER_MAP = [
        'resend' => ResendProvider::class,
        'brevo' => BrevoProvider::class,
        'mailersend' => MailerSendProvider::class,
        'sendix' => SendixProvider::class,
        'maileroo' => MailerooProvider::class,
        'mailjet' => MailjetProvider::class,
    ];

    /** Config fields that are encrypted at rest and must be decrypted before use. */
    private const SECRET_FIELDS = ['api_key', 'api_secret'];

    public function __construct(private PDO $db)
    {
    }

    /**
     * Returns active providers in priority order, each already
     * quota-reset-checked (daily_used/monthly_used zeroed if the
     * reset date rolled over) and with decrypted config attached.
     * Providers over their local quota are excluded (plan §4).
     *
     * @return array<int, array{row: array, driver: EmailProviderInterface, config: array}>
     */
    public function activeProvidersInOrder(): array
    {
        $this->resetQuotasIfNeeded();

        $stmt = $this->db->query(
            'SELECT * FROM email_otp_providers WHERE is_active = 1 ORDER BY priority ASC, id ASC'
        );
        $rows = $stmt->fetchAll();

        $result = [];
        foreach ($rows as $row) {
            if ($row['daily_quota'] !== null && (int) $row['daily_used'] >= (int) $row['daily_quota']) {
                continue; // local daily limit reached — skip, don't attempt
            }
            if ($row['monthly_quota'] !== null && (int) $row['monthly_used'] >= (int) $row['monthly_quota']) {
                continue; // local monthly limit reached — skip
            }

            $driverClass = self::DRIVER_MAP[$row['driver_key']] ?? null;
            if ($driverClass === null) {
                continue; // unknown driver_key — no implementing class, nothing to run
            }

            $config = json_decode($row['config_json'] ?? '{}', true) ?: [];
            foreach (self::SECRET_FIELDS as $field) {
                if (!empty($config[$field])) {
                    $config[$field] = SecretManager::decrypt($config[$field]);
                }
            }

            $result[] = [
                'row' => $row,
                'driver' => new $driverClass(),
                'config' => $config,
            ];
        }

        return $result;
    }

    /** Every registered driver_key, active or not — used by the Admin Panel to seed/display all rows. */
    public static function knownDriverKeys(): array
    {
        return array_keys(self::DRIVER_MAP);
    }

    /**
     * Rolls daily_used back to 0 for any provider whose quota_reset_date
     * is in the past (new day), and monthly_used back to 0 on the 1st of
     * a new month. Runs once per activeProvidersInOrder() call — cheap
     * enough (single UPDATE, only touches stale rows) not to need a cron.
     */
    private function resetQuotasIfNeeded(): void
    {
        $this->db->exec(
            "UPDATE email_otp_providers
             SET daily_used = 0, quota_reset_date = CURDATE()
             WHERE quota_reset_date IS NULL OR quota_reset_date < CURDATE()"
        );
        $this->db->exec(
            "UPDATE email_otp_providers
             SET monthly_used = 0
             WHERE DAY(CURDATE()) = 1 AND updated_at < CURDATE()"
        );
    }
}
