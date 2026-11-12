<?php
declare(strict_types=1);
namespace LeenkatForms\Submissions;
/** State transitions contract; not wired to public submissions yet. */
final class EntryStatus {
    public const DRAFT = 'draft';
    public const PENDING_PAYMENT = 'pending_payment';
    public const COMPLETE = 'complete';
    public const SPAM = 'spam';
    public const TRASHED = 'trashed';
    public const PROCESSING_FAILED = 'processing_failed';
    private const TRANSITIONS = [
        self::DRAFT => [self::PENDING_PAYMENT, self::COMPLETE, self::SPAM, self::TRASHED],
        self::PENDING_PAYMENT => [self::COMPLETE, self::PROCESSING_FAILED, self::TRASHED],
        self::COMPLETE => [self::SPAM, self::TRASHED],
        self::SPAM => [self::COMPLETE, self::TRASHED],
        self::PROCESSING_FAILED => [self::PENDING_PAYMENT, self::COMPLETE, self::TRASHED],
        self::TRASHED => [self::COMPLETE, self::SPAM],
    ];
    public static function all(): array { return array_keys(self::TRANSITIONS); }
    public static function canTransition(string $from, string $to): bool {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }
}
