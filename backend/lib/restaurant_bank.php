<?php
/**
 * Anydrop — Restaurant Bank Details helpers (PENDING.md §15, migration
 * 59)
 *
 * Shared validation + serialization for bank-details-get.php /
 * bank-details-save.php, same "one shared lib, thin endpoints" split
 * as restaurant_closures.php.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/response.php';

/**
 * Validates account_holder_name / bank_name / account_number /
 * ifsc_code / upi_id, returning the normalized values, or calls
 * respond_error and exits on the first invalid field.
 *
 * IFSC format (4 letters, 0, 6 alphanumeric — the standard RBI IFSC
 * shape) is checked the same "loosely format-checked, not a live
 * bank-registry lookup" way profile-update.php checks gst_number/
 * fssai_number — this container has no way to call a real IFSC
 * lookup API, and even in production an unreachable third-party
 * lookup shouldn't be a hard blocker on saving a restaurant's payout
 * details. account_number is checked as digits-only within a sane
 * length range (Indian bank account numbers vary 9-18 digits
 * depending on the bank) rather than a fixed length, since there's no
 * single correct length across banks.
 */
function validate_bank_fields(
    string $accountHolderName,
    string $bankName,
    string $accountNumber,
    string $ifscCode,
    ?string $upiId
): array {
    $accountHolderName = trim($accountHolderName);
    $bankName = trim($bankName);
    $accountNumber = trim($accountNumber);
    $ifscCode = strtoupper(trim($ifscCode));
    $upiId = $upiId !== null ? trim($upiId) : null;

    $invalidFields = [];

    if ($accountHolderName === '' || mb_strlen($accountHolderName) > 100) {
        $invalidFields[] = 'account_holder_name';
    }
    if ($bankName === '' || mb_strlen($bankName) > 100) {
        $invalidFields[] = 'bank_name';
    }
    if (!preg_match('/^[0-9]{9,18}$/', $accountNumber)) {
        $invalidFields[] = 'account_number';
    }
    if (!preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifscCode)) {
        $invalidFields[] = 'ifsc_code';
    }
    // upi_id is optional — a bare "@" check catches an obviously
    // malformed value (missing handle) without pretending to validate
    // against real PSP handle lists, same reasoning as the IFSC check
    // above.
    if ($upiId !== null && $upiId !== '' && !preg_match('/^[\w.\-]{2,256}@[\w]{2,64}$/', $upiId)) {
        $invalidFields[] = 'upi_id';
    }

    if (!empty($invalidFields)) {
        respond_error('validation_error', 422, ['fields' => $invalidFields]);
    }

    return [$accountHolderName, $bankName, $accountNumber, $ifscCode, $upiId !== '' ? $upiId : null];
}

/** Restaurant-facing serialization — account_number is masked to its
 * last 4 digits everywhere except immediately after a successful save
 * (see bank-details-save.php's own response), same "don't echo
 * sensitive data back on every read" reasoning as password fields
 * being unset() elsewhere in this backend. A restaurant owner who just
 * typed their own account number doesn't need it redisplayed in full
 * on every subsequent screen load, and a masked display is enough to
 * confirm "yes, this is the account I set up" at a glance. */
function serialize_bank_details_for_restaurant(array $row): array
{
    $accountNumber = (string) $row['account_number'];
    $masked = strlen($accountNumber) > 4
        ? str_repeat('X', strlen($accountNumber) - 4) . substr($accountNumber, -4)
        : $accountNumber;

    return [
        'account_holder_name' => $row['account_holder_name'],
        'bank_name' => $row['bank_name'],
        'account_number_masked' => $masked,
        'ifsc_code' => $row['ifsc_code'],
        'upi_id' => $row['upi_id'],
        'verification_status' => $row['verification_status'],
        'admin_remarks' => $row['admin_remarks'],
        'updated_at' => $row['updated_at'],
    ];
}
