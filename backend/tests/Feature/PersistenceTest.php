<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Support\Facades\DB;
use App\Services\Planner;
class PersistenceTest extends TestCase {
 public function test_file_database_survives_reconnect_and_backup_contains_previous_state():void {
  $path=tempnam(sys_get_temp_dir(),'wb-db-');$dir=sys_get_temp_dir().'/wb-backups-'.uniqid();
  config(['database.connections.sqlite.database'=>$path,'workblocks.backup_directory'=>$dir]);DB::purge('sqlite');
  try {
   $this->artisan('migrate',['--force'=>true])->assertExitCode(0);
   $plan=json_decode(file_get_contents(base_path('../examples/rani-plan.json')),true);$p=app(Planner::class);$p->import($plan);
   DB::disconnect();$this->assertEquals(23,DB::table('work_blocks')->count());
   $plan['weeks']=[];$p->import($plan);$files=glob($dir.'/*.sqlite');$this->assertCount(2,$files);
   $counts=[];foreach($files as $f){$pdo=new \PDO('sqlite:'.$f);$counts[]=(int)$pdo->query('SELECT COUNT(*) FROM work_blocks')->fetchColumn();}
   sort($counts);$this->assertSame([0,23],$counts);$this->assertEquals(0,DB::table('work_blocks')->count());
  }finally{DB::purge('sqlite');foreach(glob($dir.'/*')?:[] as $f)unlink($f);if(is_dir($dir))rmdir($dir);foreach(glob($path.'*')?:[] as $f)unlink($f);}
 }
}
