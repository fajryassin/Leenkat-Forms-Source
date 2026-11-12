<?php
declare(strict_types=1);
namespace LeenkatForms\Workflow;

use LeenkatForms\Settings\SiteSettings;

final class EmailLayout {
    public static function render(string $body,string $template,string $title,array $email,string $mode='auto'): string {
        [$accent,$defaultFont,$padding,$width]=match($template){'compact'=>['#444444','Arial, sans-serif',16,520],'modern'=>['#087e84','Arial, sans-serif',30,640],'elegant'=>['#69583a','Georgia, serif',36,640],'tech'=>['#193344','monospace',24,640],default=>['#444444','Arial, sans-serif',30,640]};
        $light=$email[$mode==='dark'?'dark':'light'];$dark=$email['dark'];$font=self::font($light['typography'],$defaultFont);$darkFont=self::font($dark['typography'],$defaultFont);
        $accentHeading=in_array($template,['modern','elegant','tech'],true);$header=$accentHeading?$accent:$light['body'];$headerText=$accentHeading?'#ffffff':$light['text'];
        $css='body,table,td{font-family:'.$font.'}.lkf-email-body a{color:'.$light['links'].'}.lkf-email-body table{color:inherit}.lkf-email-logo-dark{display:none;max-height:0;overflow:hidden}';
        if($mode==='auto'){
            $rules='.lkf-email-background{background:'.$dark['background'].'!important}.lkf-email-panel,.lkf-email-content,.lkf-email-logo-cell{background:'.$dark['body'].'!important;color:'.$dark['text'].'!important}.lkf-email-content,.lkf-email-content th,.lkf-email-content td{color:'.$dark['text'].'!important;font-family:'.$darkFont.'!important}.lkf-email-body a{color:'.$dark['links'].'!important}';
            if(!$accentHeading)$rules.='.lkf-email-heading{background:'.$dark['body'].'!important;color:'.$dark['text'].'!important}';
            $rules.='.lkf-email-heading{font-family:'.$darkFont.'!important}.lkf-email-logo-light{display:none!important;max-height:0!important;overflow:hidden!important}.lkf-email-logo-dark{display:inline-block!important;max-height:none!important;overflow:visible!important}';
            $css.='@media(prefers-color-scheme:dark){'.$rules.'}';
        }
        $logo='';$lightImage=SiteSettings::image((int)$email['light']['header_image']);$darkImage=SiteSettings::image((int)$email['dark']['header_image']) ?? $lightImage;
        $imageTag=static function(?array $image,string $class,string $size):string {
            if(!$image)return '';[$maxWidth,$maxHeight]=match($size){'small'=>[240,120],'large'=>[500,240],default=>[350,180]};
            $scale=min(1,$maxWidth/max(1,$image['width']),$maxHeight/max(1,$image['height']));$imageWidth=max(1,(int)round($image['width']*$scale));$imageHeight=max(1,(int)round($image['height']*$scale));
            return '<img class="'.$class.'" src="'.esc_url($image['url']).'" alt="'.esc_attr($image['alt']).'" width="'.$imageWidth.'" height="'.$imageHeight.'" style="display:'.($class==='lkf-email-logo-dark'?'none':'inline-block').';max-width:100%;height:auto;border:0">';
        };
        $lightSize=$email['light']['header_image_size'] ?? 'medium';$darkSize=$email['dark']['header_image_size'] ?? 'medium';
        if($mode==='auto'){$images=$imageTag($lightImage,'lkf-email-logo-light',$lightSize).$imageTag($darkImage,'lkf-email-logo-dark',$darkSize);}else $images=$imageTag($mode==='dark'?$darkImage:$lightImage,'lkf-email-logo',$mode==='dark'?$darkSize:$lightSize);
        if($images!=='')$logo='<tr><td class="lkf-email-logo-cell" style="padding:24px '.$padding.'px 8px;background:'.$light['body'].'">'.$images.'</td></tr>';
        $body=nl2br($body,false);
        $language=function_exists('get_locale')?str_replace('_','-',get_locale()):'en-US';
        $direction=function_exists('is_rtl')&&is_rtl()?'rtl':'ltr';
        return '<!doctype html><html lang="'.esc_attr($language).'" dir="'.esc_attr($direction).'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light dark"><meta name="supported-color-schemes" content="light dark"><style>'.$css.'</style></head><body class="lkf-email-background lkf-email-body" style="margin:0;background:'.$light['background'].';color:'.$light['text'].';font-family:'.$font.';font-size:15px;line-height:1.6"><table role="presentation" width="100%" class="lkf-email-background" style="background:'.$light['background'].'"><tr><td style="padding:24px 12px"><table role="presentation" width="100%" class="lkf-email-panel" style="max-width:'.$width.'px;margin:auto;background:'.$light['body'].';border:1px solid '.$accent.';border-collapse:collapse">'.$logo.'<tr><td class="lkf-email-heading" style="padding:'.$padding.'px;background:'.$header.';color:'.$headerText.';font-size:22px;font-weight:bold;font-family:'.$font.'">'.esc_html($title).'</td></tr><tr><td class="lkf-email-content" style="padding:'.$padding.'px;background:'.$light['body'].';color:'.$light['text'].';font-family:'.$font.'">'.$body.'</td></tr></table></td></tr></table></body></html>';
    }
    private static function font(string $value,string $fallback): string {return match($value){'sans'=>'Arial, Helvetica, sans-serif','serif'=>'Georgia, Times New Roman, serif','mono'=>'Courier New, monospace',default=>$fallback};}
}
