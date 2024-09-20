<?php
/**
 * Plugin Name: Relay Object Cache for WordPress
 * Plugin URI:  https://github.com/avunu/wp-object-cache
 * Version:     1.0
 * Author:      Avunu LLC
 * Author URI:  https://avu.nu
 * Description: An opinionated, concise Redis Object Cache using cachewerk/relay with Unix socket support.
 * License:     GPLv3
 * License URI: https://www.gnu.org/licenses/quick-guide-gplv3.html
 */

function wp_cache_add(string $key, mixed $value, string $group = 'default', int $expiration = 0): bool {
    global $wp_object_cache;
    return $wp_object_cache->add($key, $value, $group, $expiration);
}

function wp_cache_close(): bool {
    return true;
}

function wp_cache_decr(string $key, int $offset = 1, string $group = 'default'): int|bool {
    global $wp_object_cache;
    return $wp_object_cache->decr($key, $offset, $group);
}

function wp_cache_delete(string $key, string $group = 'default'): bool {
    global $wp_object_cache;
    return $wp_object_cache->delete($key, $group);
}

function wp_cache_flush(): bool {
    global $wp_object_cache;
    return $wp_object_cache->flush();
}

function wp_cache_get(string $key, string $group = 'default', bool $force = false, &$found = null): mixed {
    global $wp_object_cache;
    return $wp_object_cache->get($key, $group, $force, $found);
}

function wp_cache_get_multi(array $groups): array {
    global $wp_object_cache;
    return $wp_object_cache->get_multi($groups);
}

function wp_cache_incr(string $key, int $offset = 1, string $group = 'default'): int|bool {
    global $wp_object_cache;
    return $wp_object_cache->incr($key, $offset, $group);
}

function wp_cache_init(): void {
    global $wp_object_cache;
    $wp_object_cache = new WP_Object_Cache();
}

function wp_cache_replace(string $key, mixed $value, string $group = 'default', int $expiration = 0): bool {
    global $wp_object_cache;
    return $wp_object_cache->replace($key, $value, $group, $expiration);
}

function wp_cache_set(string $key, mixed $value, string $group = 'default', int $expiration = 0): bool {
    global $wp_object_cache;
    return $wp_object_cache->set($key, $value, $group, $expiration);
}

function wp_cache_switch_to_blog(int $blog_id): void {
    global $wp_object_cache;
    $wp_object_cache->switch_to_blog($blog_id);
}

function wp_cache_add_global_groups(array $groups): void {
    global $wp_object_cache;
    $wp_object_cache->add_global_groups($groups);
}

function wp_cache_add_non_persistent_groups(array $groups): void {
    global $wp_object_cache;
    $wp_object_cache->add_non_persistent_groups($groups);
}

class WP_Object_Cache {

    private \Relay\Relay $redis;
    private array $cache = [];
    private array $global_groups = [
        'blog-details',
        'blog-id-cache',
        'blog-lookup',
        'global-posts',
        'networks',
        'rss',
        'sites',
        'site-details',
        'site-lookup',
        'site-options',
        'site-transient',
        'users',
        'useremail',
        'userlogins',
        'usermeta',
        'user_meta',
        'userslugs',
    ];
    private array $no_redis_groups = ['comment', 'counts'];
    private string $blog_prefix = '';
    private bool $multisite;

    public function __construct() {
        $this->redis = new \Relay\Relay(
            host: '/run/redis.sock',
            context: [
                'use-cache' => false,
                'compression' => 2, // COMPRESSION_ZSTD
                'serializer' => 2, // SERIALIZER_IGBINARY
                'prefix' => 'wp:'
            ],
            database: 0,
        );

        $this->multisite   = is_multisite();
        $this->blog_prefix = $this->multisite ? get_current_blog_id() . ':' : '';
    }

    public function add(string $key, mixed $value, string $group = 'default', int $expiration = 0): bool {
        if (wp_suspend_cache_addition()) {
            return false;
        }

        if (isset($this->cache[$group][$key]) && $this->cache[$group][$key] !== false) {
            return false;
        }

        return $this->set($key, $value, $group, $expiration);
    }

    public function replace(string $key, mixed $value, string $group = 'default', int $expiration = 0): bool {
        if (in_array($group, $this->no_redis_groups)) {
            if (!isset($this->cache[$group][$key])) {
                return false;
            }
        } else {
            if (!$this->redis->exists($this->build_key($key, $group))) {
                return false;
            }
        }

        return $this->set($key, $value, $group, $expiration);
    }

    public function delete(string $key, string $group = 'default'): bool {
        unset($this->cache[$group][$key]);

        if (in_array($group, $this->no_redis_groups)) {
            return true;
        }

        return (bool) $this->redis->del($this->build_key($key, $group));
    }

    public function flush(): bool {
        $this->cache = [];
        $this->redis->flushDb();
        return true;
    }

    public function get(string $key, string $group = 'default', bool $force = false, &$found = null): mixed {
        if (!$force && isset($this->cache[$group][$key])) {
            $found = true;
            return is_object($this->cache[$group][$key]) ? clone $this->cache[$group][$key] : $this->cache[$group][$key];
        }

        if (in_array($group, $this->no_redis_groups)) {
            $found = false;
            return false;
        }

        $value = $this->redis->get($this->build_key($key, $group));

        if ($value === false) {
            $found = false;
            $this->cache[$group][$key] = false;
            return false;
        }

        $found = true;
        $this->cache[$group][$key] = $value;
        return $value;
    }

    public function get_multi(array $groups): array {
        $cache      = [];
        $fetch_keys = [];
        $map        = [];

        foreach ($groups as $group => $keys) {
            if (in_array($group, $this->no_redis_groups)) {
                foreach ($keys as $key) {
                    $cache[$group][$key] = $this->get($key, $group);
                }
                continue;
            }

            foreach ($keys as $key) {
                if (isset($this->cache[$group][$key])) {
                    $cache[$group][$key] = $this->cache[$group][$key];
                } else {
                    $redis_key         = $this->build_key($key, $group);
                    $fetch_keys[]      = $redis_key;
                    $map[$redis_key]   = [$group, $key];
                }
            }
        }

        if (!empty($fetch_keys)) {
            $results = $this->redis->mget($fetch_keys);

            foreach (array_combine($fetch_keys, $results) as $redis_key => $value) {
                [$group, $key]           = $map[$redis_key];
                $this->cache[$group][$key] = $cache[$group][$key] = $value !== false ? $value : false;
            }
        }

        return $cache;
    }

    public function set(string $key, mixed $value, string $group = 'default', int $expiration = 0): bool {
        $this->cache[$group][$key] = $value;

        if (in_array($group, $this->no_redis_groups)) {
            return true;
        }

        $redis_key = $this->build_key($key, $group);

        if ($expiration) {
            $this->redis->setex($redis_key, $expiration, $value);
        } else {
            $this->redis->set($redis_key, $value);
        }

        return true;
    }

    public function incr(string $key, int $offset = 1, string $group = 'default'): int|bool {
        if (in_array($group, $this->no_redis_groups)) {
            $this->cache[$group][$key] = ($this->cache[$group][$key] ?? 0) + $offset;
            return $this->cache[$group][$key];
        }

        $redis_key = $this->build_key($key, $group);
        $value     = $this->redis->incrBy($redis_key, $offset);
        $this->cache[$group][$key] = $value;
        return $value;
    }

    public function decr(string $key, int $offset = 1, string $group = 'default'): int|bool {
        return $this->incr($key, -$offset, $group);
    }

    private function build_key(string $key, string $group = 'default'): string {
        $prefix = in_array($group, $this->global_groups) ? '' : $this->blog_prefix;
        return "{$prefix}{$group}:{$key}";
    }

    public function switch_to_blog(int $blog_id): void {
        $this->blog_prefix = $this->multisite ? "{$blog_id}:" : '';
    }

    public function add_global_groups(array $groups): void {
        $this->global_groups = array_merge($this->global_groups, $groups);
    }

    public function add_non_persistent_groups(array $groups): void {
        $this->no_redis_groups = array_merge($this->no_redis_groups, $groups);
    }
}

wp_cache_init();
