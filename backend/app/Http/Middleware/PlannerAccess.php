<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
class PlannerAccess {
 public function handle(Request $request,Closure $next){
  abort_unless($request->session()->get('planner_authenticated')===true,401,'Please sign in.');
  return $next($request);
 }
}
