<?php
namespace App\Services;
use Illuminate\Validation\ValidationException;
use Opis\JsonSchema\Validator;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Carbon\CarbonImmutable;
class PlanValidator {
 public function validate(mixed $document): array {
  $schema=json_decode(file_get_contents(base_path('../contracts/plan-v1.schema.json')));
  $validator=new Validator();
  $result=$validator->validate(json_decode(json_encode($document)), $schema);
  if(!$result->isValid())throw ValidationException::withMessages(['document'=>array_values((new ErrorFormatter())->formatFlat($result->error()))]);
  $d=json_decode(json_encode($document),true);
  $ids=array_column($d['priorities'],'id');$ranks=array_column($d['priorities'],'rank');
  if(count(array_unique($ids))!==count($ids)||count(array_unique($ranks))!==count($ranks))$this->fail('Priority IDs and ranks must be unique.');
  if(!in_array($d['timezone'],\DateTimeZone::listIdentifiers(),true))$this->fail('Unknown IANA timezone.');
  $weeks=[];$this->blocks($d['standing_plan']['blocks'],$d,$ids,false);
  foreach($d['weeks'] as $w){
   $start=CarbonImmutable::parse($w['week_start']);
   if(!$start->isMonday()||isset($weeks[$w['week_start']]))$this->fail('Each week_start must be a unique Monday.');
   $weeks[$w['week_start']]=true;
   foreach($w['blocks'] as $b){if(CarbonImmutable::parse($b['date'])->startOfWeek()->toDateString()!==$w['week_start'])$this->fail('A block date falls outside its week.');if($b['date']<$d['effective_from'])$this->fail('Blocks cannot precede effective_from.');}
   $this->blocks($w['blocks'],$d,$ids,true);
  }
  return $d;
 }
 private function blocks(array $blocks,array $d,array $ids,bool $dated):void {
  $positions=[];
  foreach($blocks as $b){
   if(!in_array($b['priority_id'],$ids,true))$this->fail('A block references an unknown priority_id.');
   $key=($b[$dated?'date':'day']).'/'.$b['slot'].'/'.($b['position']??1);
   if(isset($positions[$key]))$this->fail('Block positions must be unique within each day and slot.');$positions[$key]=true;
  }
 }
 private function fail(string $message):never {throw ValidationException::withMessages(['document'=>$message]);}
}
