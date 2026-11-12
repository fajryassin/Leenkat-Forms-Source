<?php
declare(strict_types=1);
namespace LeenkatForms\Submissions;
use WP_REST_Request;

final class NativeSubmission {
    private static ?array $memoryReceipt=null;
    public static function hooks(): void {
        ConfirmationReceipt::hooks();
        add_action('template_redirect',static function():void{
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Anonymous public form POST is validated by the existing authoritative endpoint, signed protection/CAPTCHA policy and idempotency checks; no administrative action is exposed.
            if(isset($_GET['lkf_receipt']))ConfirmationReceipt::privateHeaders();
            // Reuse the authoritative endpoint; only the no-storage return rendering differs.
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing -- Server method/length metadata is compared with exact literals or cast and bounded; it is not WordPress-slash-encoded form content or HTML output. Anonymous public form POST is validated by the existing authoritative endpoint, signed protection/CAPTCHA policy and idempotency checks; no administrative action is exposed.
            if(($_SERVER['REQUEST_METHOD'] ?? '')==='POST' && ($_POST['action'] ?? '')==='lkf_submit')self::process(true);
        },0);
        add_action('admin_post_lkf_submit',[self::class,'handle']);
        add_action('admin_post_nopriv_lkf_submit',[self::class,'handle']);
    }
    public static function handle(): void {self::process(false);}
    private static function process(bool $samePage): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Anonymous public form POST is validated by the existing authoritative endpoint, signed protection/CAPTCHA policy and idempotency checks; no administrative action is exposed.
        $post=wp_unslash($_POST);$id=absint($post['form_id'] ?? 0);
        $request=new WP_REST_Request('POST');$request->set_param('id',$id);
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Server method/length metadata is compared with exact literals or cast and bounded; it is not WordPress-slash-encoded form content or HTML output.
        $request->set_header('content-type','multipart/form-data');$length=$_SERVER['CONTENT_LENGTH'] ?? '0';$request->set_header('content-length',is_string($length)&&ctype_digit($length)?(string)(int)$length:'0');
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Anonymous public form POST is validated by the existing authoritative endpoint, signed protection/CAPTCHA policy and idempotency checks; no administrative action is exposed.
        $request->set_param('payload',wp_json_encode(array_intersect_key($post,array_flip(['submission_uuid','fields','email_confirmations','captcha_tokens','website_confirmation','security_token','security_traps','service_captcha_token'])),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));$request->set_file_params($_FILES);
        $result=PublicEndpoint::submit($request);
        $data=is_wp_error($result)?[]:$result->get_data();$confirmation=$data['confirmation'] ?? [];
        // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Configured external URL confirmations intentionally allow navigation after ConfirmationSettings::validUrl rejects unsafe schemes and credentials.
        if(($confirmation['type'] ?? '')==='redirect' && \LeenkatForms\Forms\ConfirmationSettings::validUrl($confirmation['url'] ?? '')){wp_redirect($confirmation['url'],303);exit;}
        if(!empty($confirmation['entry_preview'])){
            if(!empty($data['unstored']))ConfirmationReceipt::document($confirmation);
            wp_safe_redirect($data['receipt_url'],303);exit;
        }
        $receipt=['form'=>$id,'instance'=>max(1,min(100,(int)($post['form_instance'] ?? 1))),'error'=>is_wp_error($result),'message'=>is_wp_error($result)?$result->get_error_message():$data['message'],'confirmation'=>is_wp_error($result)?null:$confirmation];
        if(is_wp_error($result))$receipt['field_errors']=$result->get_error_data()['field_errors'] ?? [];
        if(!empty($receipt['field_errors'])){
            if(!$samePage){wp_safe_redirect(self::returnUrl($post),307);exit;}
            // Rejected values remain in this request only; preserve conditional source
            // selections and valid controls without putting answers in an error receipt.
            $receipt['fields']=is_array($post['fields'] ?? null)?$post['fields']:[];
            $receipt['email_confirmations']=is_array($post['email_confirmations'] ?? null)?$post['email_confirmations']:[];
            ConfirmationReceipt::privateHeaders();status_header(max(400,min(599,(int)($result->get_error_data()['status']??400))));self::$memoryReceipt=$receipt;return;
        }
        if(!empty($data['unstored']) && $samePage){
            // No answers, including personalized Smart Tags, are persisted when
            // entry storage is disabled. Render within the original theme in memory.
            ConfirmationReceipt::privateHeaders();self::$memoryReceipt=$receipt;return;
        }
        if(!empty($data['unstored'])){
            // Forward the original POST, keeping the existing UUID. The authoritative
            // endpoint acknowledges this retry without sending notifications twice.
            // A 307 keeps answers out of persistent receipts, cookies and URLs.
            wp_safe_redirect(self::returnUrl($post),307);exit;
        }
        $token=wp_generate_password(32,false,false);
        set_transient('lkf_result_'.$token,$receipt,ConfirmationReceipt::TTL);
        $return=self::returnUrl($post);
        $return=remove_query_arg(['lkf_receipt','lkf_result_form'],$return);
        wp_safe_redirect(add_query_arg(['lkf_receipt'=>$token,'lkf_result_form'=>$id],$return),303);exit;
    }
    private static function returnUrl(array $post): string {
        $return=wp_validate_redirect(is_string($post['return_url'] ?? null)?$post['return_url']:'',home_url('/'));
        $target=wp_parse_url($return);$site=wp_parse_url(home_url('/'));
        foreach(['scheme','host','port'] as $key)if(($target[$key] ?? null)!==($site[$key] ?? null))return home_url('/');
        if(isset($target['user'])||isset($target['pass'])||str_contains($target['path'] ?? '','/wp-admin/'))return home_url('/');
        return $return;
    }
    public static function receipt(int $id,array $query,?int $instance=null): ?array {
        if(self::$memoryReceipt){$receipt=self::$memoryReceipt;}
        else{
            $token=$query['lkf_receipt'] ?? null;
            if(!is_string($token)||!preg_match('/^[A-Za-z0-9]{32}$/D',$token)||(int)($query['lkf_result_form'] ?? 0)!==$id)return null;
            $receipt=get_transient('lkf_result_'.$token);
        }
        return is_array($receipt)&&($receipt['form'] ?? 0)===$id&&($instance===null||($receipt['instance'] ?? 1)===$instance)?$receipt:null;
    }
}
