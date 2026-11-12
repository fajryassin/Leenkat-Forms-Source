<?php
declare(strict_types=1);
namespace LeenkatForms\Forms;
/** Deterministic, read-only checks of the schema-v1 contract. */
final class DefinitionContractTests {
    public static function cases(): array {
        $base = ['schema_version'=>1, 'title'=>'Test', 'fields'=>[['id'=>'email','type'=>'email','label'=>'Email','required'=>true]]];
        $cases = [
            'valid minimal definition' => [DefinitionValidator::validate($base) === [], true],
            'reject duplicate IDs' => [DefinitionValidator::validate(['schema_version'=>1,'title'=>'Test','fields'=>[['id'=>'email','type'=>'email','label'=>'Email'],['id'=>'email','type'=>'text','label'=>'Text']]]) !== [], true],
            'reject unknown step field' => [DefinitionValidator::validate($base + ['steps'=>[['id'=>'first','field_ids'=>['missing']]]]) !== [], true],
            'reject invalid schema version' => [DefinitionValidator::validate(['schema_version'=>2,'title'=>'Test','fields'=>[]]) !== [], true],
            'reject non-list fields' => [DefinitionValidator::validate(['schema_version'=>1,'title'=>'Test','fields'=>['email'=>['id'=>'email','type'=>'email','label'=>'Email']]]) !== [], true],
            'reject malformed required' => [DefinitionValidator::validate(['schema_version'=>1,'title'=>'Test','fields'=>[['id'=>'email','type'=>'email','label'=>'Email','required'=>'yes']]]) !== [], true],
            'reject duplicate step assignment' => [DefinitionValidator::validate($base + ['steps'=>[['id'=>'first','field_ids'=>['email']],['id'=>'second','field_ids'=>['email']]]]) !== [], true],
            'stable canonical JSON hash' => [DefinitionCodec::hash($base) === DefinitionCodec::hash(['fields'=>$base['fields'],'title'=>'Test','schema_version'=>1]), true],
        ];
        return array_map(static fn(array $case): bool => $case[0] === $case[1], $cases);
    }
    public static function run(): bool { return !in_array(false, self::cases(), true); }
}
