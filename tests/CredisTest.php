<?php

require_once dirname(__FILE__).'/../Client.php';

class CredisTest extends PHPUnit_Framework_TestCase
{

  /** @var \Credis\Client */
  protected $credis;

  protected $config;

  protected $useStandalone = FALSE;

  protected function setUp()
  {
    if($this->config === NULL) {
      $configFile = dirname(__FILE__).'/test_config.json';
      if( ! file_exists($configFile) || ! ($config = file_get_contents($configFile))) {
        $this->markTestSkipped('Could not load '.$configFile);
      }
      $this->config = json_decode($config);
    }
    $this->credis = new \Credis\Client($this->config->host, $this->config->port, $this->config->timeout);
    if($this->useStandalone) {
      $this->credis->forceStandalone();
    }
  }

  protected function tearDown()
  {
    if($this->credis) {
      $this->credis->flushDb();
      $this->credis->close();
      $this->credis = NULL;
    }
  }

  public function testStrings()
  {
    // Basic get/set
    $this->credis->set('foo','FOO');
    $this->assertEquals('FOO', $this->credis->get('foo'));

    // Empty string
    $this->credis->set('empty','');
    $this->assertEquals('', $this->credis->get('empty'));

    // UTF-8 characters
    $utf8str = str_repeat("quarter: ¼, micro: µ, thorn: Þ, ", 500);
    $this->credis->set('utf8',$utf8str);
    $this->assertEquals($utf8str, $this->credis->get('utf8'));

    // Array
    $this->credis->set('bar','BAR');
    $mget = $this->credis->mget(array('foo','bar','empty'));
    $this->assertTrue(in_array('FOO', $mget));
    $this->assertTrue(in_array('BAR', $mget));
    $this->assertTrue(in_array('', $mget));

    // Non-array
    $mget = $this->credis->mget('foo','bar');
    $this->assertTrue(in_array('FOO', $mget));
    $this->assertTrue(in_array('BAR', $mget));

    // Delete strings, null response
    $this->assertEquals(2, $this->credis->del('foo','bar'));
    $this->assertNull($this->credis->get('foo'));
    $this->assertNull($this->credis->get('bar'));

    // Long string
    $longString = str_repeat(md5('asd')."\r\n", 500);
    $this->assertEquals('OK', $this->credis->set('long', $longString));
    $this->assertEquals($longString, $this->credis->get('long'));
  }

  public function testSets()
  {
    // Multiple arguments
    $this->assertEquals(2, $this->credis->sAdd('myset', 'Hello', 'World'));

    // Array Arguments
    $this->assertEquals(1, $this->credis->sAdd('myset', array('Hello','Cruel','World')));

    // Non-empty set
    $members = $this->credis->sMembers('myset');
    $this->assertEquals(3, count($members));
    $this->assertTrue(in_array('Hello', $members));

    // Empty set
    $this->assertEquals(array(), $this->credis->sMembers('noexist'));
  }

  public function testFalsey()
  {
    $this->assertEquals(\Credis\Client::TYPE_NONE, $this->credis->type('foo'));
  }

  public function testPipeline()
  {
    $longString = str_repeat(md5('asd')."\r\n", 500);
    $reply = $this->credis->pipeline()
        ->set('a', 123)
        ->get('a')
        ->sAdd('b', 123)
        ->sMembers('b')
        ->set('empty','')
        ->get('empty')
        ->set('big', $longString)
        ->get('big')
        ->exec();
    $this->assertEquals(array(
      true, 123, 1, array(123), true, '', true, $longString
    ), $reply);

    $this->assertEquals(array(), $this->credis->pipeline()->exec());
  }

  public function testTransaction()
  {
    $reply = $this->credis->multi()
        ->incr('foo')
        ->incr('bar')
        ->exec();
    $this->assertEquals(array(1,1), $reply);

    $reply = $this->credis->pipeline()->multi()
        ->incr('foo')
        ->incr('bar')
        ->exec();
    $this->assertEquals(array(2,2), $reply);

    $reply = $this->credis->multi()->pipeline()
        ->incr('foo')
        ->incr('bar')
        ->exec();
    $this->assertEquals(array(3,3), $reply);

    $reply = $this->credis->multi()
        ->set('a', 3)
        ->lpop('a')
        ->exec();
    $this->assertEquals(2, count($reply));
    $this->assertEquals(true, $reply[0]);
    $this->assertFalse($reply[1]);
  }

}
