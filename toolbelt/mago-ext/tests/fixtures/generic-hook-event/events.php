<?php

declare(strict_types=1);

namespace Civi\Core\Event;

class GenericHookEvent {
  public function __get(string $name): mixed { return null; }
  public function __set(string $name, mixed $value): void {}
  public function __isset(string $name): bool { return false; }
}

class TypedEvent extends GenericHookEvent {}

class Bag {
  public function __get(string $name): mixed { return null; }
  public function __set(string $name, mixed $value): void {}
  public function __isset(string $name): bool { return false; }
}

function listen(GenericHookEvent $hook, TypedEvent $typed, Bag $bag): void {
  $hook->panels = [];
  $typed->panels = [];
  $bag->panels = [];
  echo (string) $hook->builder, (string) $typed->builder, (string) $bag->builder;
}
