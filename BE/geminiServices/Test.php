<?php
class Test {
    public function sayHello() {
        response_json(['message' => 'Hello from Test service!']);
    }
}