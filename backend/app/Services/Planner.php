<?php
namespace App\Services;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\CarbonImmutable;
class Planner {
 const TABLES=['plan_settings','priorities','template_blocks','plan_weeks','work_blocks'];
 public function snapshot():array {return collect(self::TABLES)->mapWithKeys(fn($t)=>[$t=>DB::table($t)->get()->map(fn($r)=>(array)$r)->all()])->all();}
 public function backup():?string {
  if(config('database.connections.sqlite.database')===':memory:')return null;
  $dir=config('workblocks.backup_directory');if(!is_dir($dir))mkdir($dir,0700,true);
  $path=$dir.'/'.now()->format('Ymd-His').'-'.Str::uuid().'.sqlite';
  DB::statement('VACUUM INTO '.DB::getPdo()->quote($path));return $path;
 }
 public function import(array $d):string {
  $this->backup();
  return DB::transaction(function()use($d){
   $id=(string)Str::uuid();DB::table('plan_imports')->insert(['id'=>$id,'plan_name'=>$d['plan_name'],'snapshot'=>json_encode($this->snapshot()),'document'=>json_encode($d),'created_at'=>now(),'updated_at'=>now()]);
   DB::table('work_blocks')->where('completed',false)->delete();
   DB::table('work_blocks')->update(['priority_id'=>null]);
   foreach(['plan_settings','priorities','template_blocks','plan_weeks'] as $table)DB::table($table)->delete();
   DB::table('plan_settings')->insert(['document'=>json_encode(collect($d)->except(['priorities','standing_plan','weeks'])->all()+['standing_notes'=>$d['standing_plan']['notes']??''])]);
   foreach($d['priorities'] as $p)DB::table('priorities')->insert($p);
   foreach($d['standing_plan']['blocks'] as $b)DB::table('template_blocks')->insert($this->normalize($b,$d['default_block_minutes']));
   foreach($d['weeks'] as $w){DB::table('plan_weeks')->insert(['week_start'=>$w['week_start'],'notes'=>$w['notes']??'','changes'=>json_encode($w['changes']??[])]);foreach($w['blocks'] as $b)$this->insertBlock($b,$d['default_block_minutes']);}
   return $id;
  });
 }
 public function restore(string $id):void {
  $record=DB::table('plan_imports')->where('id',$id)->first();abort_unless($record,404);
  $this->backup();
  DB::transaction(function()use($record){$snapshot=json_decode($record->snapshot,true);foreach(array_reverse(self::TABLES) as $t)DB::table($t)->delete();foreach(self::TABLES as $t)foreach($snapshot[$t] as $row)DB::table($t)->insert($row);});
 }
 public function generate(string $start):void {
  DB::transaction(function()use($start){
   abort_if(DB::table('plan_weeks')->where('week_start',$start)->exists(),409,'This week already exists.');
   $settings=json_decode(DB::table('plan_settings')->value('document')??'{}',true);abort_unless(isset($settings['effective_from']),422,'Import a plan first.');
   DB::table('plan_weeks')->insert(['week_start'=>$start,'notes'=>'Created from standing plan.','changes'=>'[]']);
   foreach(DB::table('template_blocks')->get() as $b){$day=array_search($b->day,['monday','tuesday','wednesday','thursday','friday','saturday','sunday'],true);$d=CarbonImmutable::parse($start)->addDays($day)->toDateString();if($d<$settings['effective_from'])continue;$this->insertBlock(['date'=>$d,'slot'=>$b->slot,'position'=>$b->position,'priority_id'=>$b->priority_id,'duration_minutes'=>$b->duration_minutes,'notes'=>$b->notes],$settings['default_block_minutes']);}
  });
 }
 private function normalize(array $b,int $default):array{return ['day'=>$b['day'],'slot'=>$b['slot'],'position'=>$b['position']??1,'priority_id'=>$b['priority_id'],'duration_minutes'=>$b['duration_minutes']??$default,'notes'=>$b['notes']??''];}
 public function insertBlock(array $b,int $default):string {
  $id=(string)Str::uuid();$name=DB::table('priorities')->where('id',$b['priority_id'])->value('name');
  DB::table('work_blocks')->insert(['id'=>$id,'origin_id'=>$id,'original_date'=>$b['date'],'date'=>$b['date'],'slot'=>$b['slot'],'position'=>$b['position']??1,'priority_id'=>$b['priority_id'],'priority_name'=>$name,'duration_minutes'=>$b['duration_minutes']??$default,'notes'=>$b['notes']??'','completed'=>false,'revision'=>1,'created_at'=>now(),'updated_at'=>now()]);return $id;
 }
 public function block(string $id,int $revision):object {$b=DB::table('work_blocks')->where('id',$id)->first();abort_unless($b,404);abort_if($b->revision!==$revision,409,'This block changed elsewhere. Refresh and try again.');return $b;}
 public function move(string $id,array $d):void {DB::transaction(function()use($id,$d){$b=$this->block($id,$d['revision']);abort_if($b->completed,422,'Reopen completed work before moving it.');$position=$this->position($d['date'],$d['slot']);DB::table('work_blocks')->where('id',$id)->update(['date'=>$d['date'],'slot'=>$d['slot'],'position'=>$position,'notes'=>$d['notes']??$b->notes,'revision'=>$b->revision+1,'updated_at'=>now()]);$this->touchWeek($d['date']);});}
 public function complete(string $id,array $d):void {DB::transaction(function()use($id,$d){$b=$this->block($id,$d['revision']);abort_if($d['completed']&&!$b->date,422,'Assign a work date before completing a carryover block.');DB::table('work_blocks')->where('id',$id)->update(['completed'=>$d['completed'],'revision'=>$b->revision+1,'updated_at'=>now()]);});}
 public function split(string $id,array $d):void {DB::transaction(function()use($id,$d){$b=$this->block($id,$d['revision']);abort_if($b->completed||$d['first_minutes']>=$b->duration_minutes,422,'Split must leave work remaining.');abort_if(($d['complete_first']??false)&&!$b->date,422,'Assign a work date before logging partial work.');$remainder=(array)$b;$remainder['id']=(string)Str::uuid();$remainder['duration_minutes']=$b->duration_minutes-$d['first_minutes'];$remainder['date']=$d['date'];$remainder['slot']=$d['slot'];$remainder['position']=$this->position($d['date'],$d['slot']);$remainder['revision']=1;$remainder['created_at']=$remainder['updated_at']=now();DB::table('work_blocks')->insert($remainder);DB::table('work_blocks')->where('id',$id)->update(['duration_minutes'=>$d['first_minutes'],'completed'=>$d['complete_first']??false,'revision'=>$b->revision+1,'updated_at'=>now()]);$this->touchWeek($d['date']);});}
 private function position(?string $date,string $slot):int {return (int)DB::table('work_blocks')->where('date',$date)->where('slot',$slot)->max('position')+1;}
 private function touchWeek(?string $date):void {if($date)DB::table('plan_weeks')->insertOrIgnore(['week_start'=>CarbonImmutable::parse($date)->startOfWeek()->toDateString(),'notes'=>'','changes'=>'[]']);}
}
