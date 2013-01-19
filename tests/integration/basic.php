<?php
/* Copyright 2012-present Facebook, Inc.
 * Licensed under the Apache License, Version 2.0 */

class basicTestCase extends WatchmanTestCase {

  function testFind() {
    $dir = PhutilDirectoryFixture::newEmptyFixture();
    $root = realpath($dir->getPath());

    touch("$root/foo.c");
    touch("$root/bar.txt");

    $out = $this->watchmanCommand('watch', $root);
    $this->assertEqual($root, $out['watch']);

    $this->assertFileList($root, array('bar.txt', 'foo.c'));

    // Make sure we correctly observe deletions
    $this->assertEqual(true, unlink("$root/bar.txt"));
    $this->assertFileList($root, array('foo.c'));

    // touch -> delete -> touch, should show up as exists
    $this->assertEqual(true, touch("$root/bar.txt"));
    $this->assertFileList($root, array('bar.txt', 'foo.c'));

    $this->assertEqual(true, unlink("$root/bar.txt"));

    // A moderately more complex set of changes
    $this->assertEqual(true, mkdir("$root/adir"));
    $this->assertEqual(true, mkdir("$root/adir/subdir"));
    $this->assertEqual(true, touch("$root/adir/subdir/file"));
    $this->assertEqual(true,
      rename("$root/adir/subdir", "$root/adir/overhere"));

    $this->assertFileList($root, array(
      'adir',
      'adir/overhere',
      'adir/overhere/file',
      'foo.c',
    ));

    $this->assertEqual(true,
      rename("$root/adir", "$root/bdir"));

    $this->assertFileList($root, array(
      'bdir',
      'bdir/overhere',
      'bdir/overhere/file',
      'foo.c',
    ));
  }

  function testTwoDeep() {
    $dir = PhutilDirectoryFixture::newEmptyFixture();
    $root = realpath($dir->getPath());
    $watch = $this->watchmanCommand('watch', $root);

    $this->assertFileList($root, array());

    $this->assertEqual(true, mkdir("$root/foo"));
    $this->assertEqual(true, mkdir("$root/foo/bar"));
    $this->assertEqual(3, file_put_contents("$root/foo/bar/111", "111"));

    $this->assertFileList($root, array(
      "foo",
      "foo/bar",
      "foo/bar/111"
    ));

    execx('rm -rf %s', "$root/foo/bar");

    $this->assertFileList($root, array(
      "foo",
    ));
  }

  function testSinceIssue2() {
    $dir = PhutilDirectoryFixture::newEmptyFixture();
    $root = realpath($dir->getPath());
    $watch = $this->watchmanCommand('watch', $root);
    $this->assertFileList($root, array());

    $this->watchmanCommand('log', 'debug', 'XXX: mkdir foo');
    mkdir("$root/foo");
    $this->watchmanCommand('log', 'debug', 'XXX: touch foo/111');
    touch("$root/foo/111");

    $this->assertFileListUsingSince($root, 'n:foo', array(
      'foo',
      'foo/111',
    ), null);

    $this->watchmanCommand('log', 'debug', 'XXX: mkdir foo/bar');
    mkdir("$root/foo/bar");
    $this->watchmanCommand('log', 'debug', 'XXX: touch foo/bar/222');
    touch("$root/foo/bar/222");

    $this->watchmanCommand('log', 'debug', 'XXX: wait to observe lists');
    $this->assertFileListUsingSince($root, 'n:foo',
      array(
        'foo',
        'foo/111',
        'foo/bar',
        'foo/bar/222'
      ), array(
        'foo/bar',
        'foo/bar/222'
      )
    );
    $this->watchmanCommand('log', 'debug', 'XXX: closing out');

  }

  function testSinceIssue1() {
    $dir = PhutilDirectoryFixture::newEmptyFixture();
    $root = realpath($dir->getPath());

    touch("$root/111");
    touch("$root/222");

    $watch = $this->watchmanCommand('watch', $root);
    $this->assertFileList($root, array(
      '111',
      '222'
    ));

    $initial = $this->watchmanCommand('since', $root, 'n:foo');

    mkdir("$root/bar");
    touch("$root/bar/333");

    // We should not observe 111 or 222 in this update
    $this->assertFileListUsingSince($root, 'n:foo',
      array(
        '111',
        '222',
        'bar',
        'bar/333'
      ),
      array(
        'bar',
        'bar/333'
      )
    );
  }

  function testCursor() {
    $dir = PhutilDirectoryFixture::newEmptyFixture();
    $root = realpath($dir->getPath());
    $watch = $this->watchmanCommand('watch', $root);

    $initial = $this->watchmanCommand('since', $root,
      'n:testCursor');

    $this->assertRegex('/^c:\d+:\d+$/', $initial['clock'],
      "clock seemslegit");

    touch($root . '/one');

    // Allow time for the change to be observed
    $update = $this->waitForWatchman(
      array('since', $root, 'n:testCursor'),
      function ($update) {
        return count($update['files']);
      }
    );

    $this->assertEqual('one',
      $update['files'][0]['name'], 'saw file change');
    $this->assertEqual(true,
      $update['files'][0]['new'], 'shows as new');

    $later = $this->watchmanCommand('since', $root,
      'n:testCursor');
    $this->assertEqual(array(), $later['files'], 'no changes');

    /* now to verify that the file doesn't show as new after this next
     * change */
    touch($root . '/one');

    // Allow time for the change to be observed
    $update = $this->waitForWatchman(
      array('since', $root, 'n:testCursor'),
      function ($update) {
        return count($update['files']);
      }
    );

    $this->assertEqual('one',
      $update['files'][0]['name'], 'saw file change');
    $this->assertEqual(false,
      isset($update['files'][0]['new']), 'not new');

  }
}

// vim:ts=2:sw=2:et:

