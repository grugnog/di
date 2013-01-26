#!/bin/bash
ipfw -q flush
ipfw -q pipe flush

ipfw pipe 1 config noerror
ipfw pipe 2 config noerror

ipfw add pipe 1 ip from 127.0.0.2 to any
ipfw add pipe 2 ip from any to 127.0.0.2

