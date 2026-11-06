<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
class PlannerWriteLock {
 public function handle(Request $request,Closure $next){
  if($request->isMethod('GET'))return $next($request);
  $handle=fopen(storage_path('framework/workblocks.lock'),'c');
  if(!$handle||!flock($handle,LOCK_EX))abort(503,'Planner is busy.');
  try{return $next($request);}finally{flock($handle,LOCK_UN);fclose($handle);}
 }
}
