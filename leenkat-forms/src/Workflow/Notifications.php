<?php
declare(strict_types=1);
namespace LeenkatForms\Workflow;

/** Creates durable delivery intents inside the entry transaction. */
final class Notifications {
    public static function createOutbox(int $entryId, array $definition, array $values, string $now): array|false {
        global $wpdb;
        $table = $wpdb->prefix . 'lkf_deliveries';
        $settings = \LeenkatForms\Forms\NotificationSettings::normalize($definition);
        $ids = [];
        if(empty($settings['enabled']))return [];
        foreach ($settings['items'] as $config) {
            if (!\LeenkatForms\Forms\NotificationSettings::shouldSend($config,$values)) { continue; }
            $key = hash('sha256', 'lkf-email|' . $entryId . '|' . $config['id']);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller.
            $ok = $wpdb->insert($table, [
                'entry_id'=>$entryId,'kind'=>'email_notification','destination_key'=>$config['id'],
                'status'=>'pending','attempt_count'=>0,'next_attempt_at_gmt'=>$now,
                'idempotency_key'=>$key,'created_at_gmt'=>$now,'updated_at_gmt'=>$now,
            ], ['%d','%s','%s','%s','%d','%s','%s','%s','%s']);
            if (!$ok) { return false; }
            $ids[] = (int)$wpdb->insert_id;
        }
        return $ids;
    }
    public static function send(array $config,array $definition,array $values,array $context=[]): string {
        if(!\LeenkatForms\Forms\NotificationSettings::shouldSend($config,array_replace($values,$context['field_choices'] ?? [])))return 'skipped';
        $message=NotificationMessage::compose($config,$definition,$values,$context);
        if(!$message)return 'skipped';
        $temporary=[];
        try {
            $attachments=MailAttachments::prepare($config,$definition,$values,$context,$temporary);
            if($attachments===false)return 'failed';
            return wp_mail($message['to'],$message['subject'],$message['message'],$message['headers'],$attachments)?'sent':'failed';
        } finally { MailAttachments::cleanup($temporary); }
    }
}
