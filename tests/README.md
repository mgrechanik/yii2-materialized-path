# PHPUnit tests for Yii2 Materialized Path Extension

## Installing and configuring

After you cloned this repository, go to it's root and run `composer update`. Doing this you will have Yii2 and PHPUnit installed.

Now go to the test's config directory.

```
cd ./tests/config
```

Your main concern now is to set up database for tests. Explore `main.php` config. 
If you want these default settings changed copy file `main-local.example.php` to `main-local.php` and in the latter override settings you need. 
Those settings will be merged with the settings from `main.php`.

Now you need to run migrations to create tables.  
Go to the tests directory

```
cd ..
```

You might need to make `yii` file executable: `chmod +x ./yii`  
Now run:

```
./yii migrate
```

## Running tests

The last thing to do is to run tests. You run them from the root directory.

```
cd ..
./vendor/bin/phpunit
```
