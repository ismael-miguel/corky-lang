corky-lang
==========

Corky Programming Language - The cork that fits every bottle!

What is corky?
==============

Corky is a "high"-level procedural strong-typed multi-paradigm free-form programming language.

The goal of corky is to be a language that is not-so-human-readable but easily parseable, with the goal of being compiled from and to PHP or Javascript.

Due to it's free-form nature, you don't have to worry about whitespace.

How to program in corky?
========================

**Comments**

Well, there isn't a syntax for comments.

Fear not!

Everything that isn't part of an instruction, is a comment, like Brainfuck.

This makes this file a perfect valid corky program.

**Data types**

Corky has a few basic datatypes:

 - `text` : a string of characters (equivalent to `string`)
 - `static` : a fixed, 0-decimal long numberic type (equivalent to `int`)
 - `dynamic` : a floating-point numerical type (equivalent to `float`)
 - `error` : a special type for pre-defined errors (equivalent to `Exception`s)

Corky also supports structures:
 - `list` : an array of values with the same type (equivalent to `array`)
 - `dict` : a dictionary containing properties (equivalent to `Object`)

**Basic syntax**

The syntax is really basic:

Everything followed by `:` is seen as a token.

Not everything will be evaluated as such!

For example, using :likethis won't cause an error, because it doesn't exist.

Only recognized commands will be parsed.

You can check the file `examples/hello_world.cork` to see how to make a runnable file.

