<?php namespace delivus\clients\requests;

class DelirabbitRequest
{
    public function __construct(private ?array $data = NULL) {
        $this->data = $data;
    }

    public function getBody(): ?array {
        return $this->data;
    }
}