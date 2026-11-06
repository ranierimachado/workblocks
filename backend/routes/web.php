<?php
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use App\Services\Planner;
use App\Services\PlanValidator;
use App\Http\Middleware\PlannerAccess;
use App\Http\Middleware\PlannerWriteLock;

Route::get('/',fn()=>redirect('/app/'));
Route::get('/api/session',fn(Request $r)=>['authenticated'=>$r->session()->get('planner_authenticated')===true,'csrf_token'=>csrf_token()]);
Route::post('/api/login',function(Request $r){$d=$r->validate(['password'=>'required|string']);$expected=config('workblocks.password');abort_unless(is_string($expected)&&strlen($expected)>=12,503,'Set WORKBLOCKS_PASSWORD to at least 12 characters.');abort_unless(hash_equals($expected,$d['password']),401,'Incorrect password.');$r->session()->regenerate();$r->session()->put('planner_authenticated',true);return ['csrf_token'=>csrf_token()];})->middleware('throttle:5,1');
Route::middleware([PlannerAccess::class,PlannerWriteLock::class])->prefix('api')->group(function(){
 Route::post('/logout',function(Request $r){$r->session()->invalidate();$r->session()->regenerateToken();return response()->noContent();});
 Route::get('/schema',fn()=>response()->file(base_path('../contracts/plan-v1.schema.json')));
 Route::get('/state',function(){return ['settings'=>json_decode(DB::table('plan_settings')->value('document')??'null',true),'priorities'=>DB::table('priorities')->orderBy('rank')->get(),'template'=>DB::table('template_blocks')->get(),'weeks'=>DB::table('plan_weeks')->orderBy('week_start')->get()->map(function($w){$w->changes=json_decode($w->changes);return $w;}),'blocks'=>DB::table('work_blocks')->orderBy('date')->orderBy('position')->get(),'imports'=>DB::table('plan_imports')->select('id','plan_name','created_at')->orderByDesc('created_at')->get()];});
 Route::post('/imports/preview',function(Request $r,PlanValidator $validator){$d=$validator->validate($r->input('document'));return ['plan_name'=>$d['plan_name'],'priorities'=>count($d['priorities']),'weeks'=>count($d['weeks']),'blocks'=>collect($d['weeks'])->sum(fn($w)=>count($w['blocks'])),'unfinished_to_delete'=>DB::table('work_blocks')->where('completed',false)->count(),'completed_to_keep'=>DB::table('work_blocks')->where('completed',true)->count()];});
 Route::post('/imports',function(Request $r,PlanValidator $v,Planner $p){$r->validate(['confirm_replace'=>'required|accepted']);$d=$v->validate($r->input('document'));return response()->json(['id'=>$p->import($d)],201);});
 Route::post('/imports/{id}/restore',function(string $id,Request $r,Planner $p){$r->validate(['confirm_restore'=>'required|accepted']);$p->restore($id);return response()->noContent();});
 Route::get('/backup',function(Planner $p){return response()->json(['backup_version'=>1,'exported_at'=>now()->toIso8601String(),'tables'=>$p->snapshot()])->header('Content-Disposition','attachment; filename="workblocks-backup.json"');});
 Route::post('/weeks',function(Request $r,Planner $p){$d=$r->validate(['week_start'=>'required|date_format:Y-m-d']);abort_unless(\Carbon\CarbonImmutable::parse($d['week_start'])->isMonday(),422,'Choose a Monday.');$p->generate($d['week_start']);return response()->noContent();});
 Route::patch('/blocks/{id}',function(string $id,Request $r,Planner $p){$d=$r->validate(['revision'=>'required|integer|min:1','date'=>'present|nullable|date_format:Y-m-d','slot'=>'required|in:morning,middle,evening,extra','notes'=>'sometimes|nullable|string|max:5000']);$p->move($id,$d);return response()->noContent();});
 Route::post('/blocks/{id}/completion',function(string $id,Request $r,Planner $p){$d=$r->validate(['revision'=>'required|integer|min:1','completed'=>'required|boolean']);$p->complete($id,$d);return response()->noContent();});
 Route::post('/blocks/{id}/split',function(string $id,Request $r,Planner $p){$d=$r->validate(['revision'=>'required|integer|min:1','first_minutes'=>'required|integer|min:1','date'=>'present|nullable|date_format:Y-m-d','slot'=>'required|in:morning,middle,evening,extra','complete_first'=>'sometimes|boolean']);$p->split($id,$d);return response()->noContent();});
});
