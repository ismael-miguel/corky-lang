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

 - `:text`    : a set of characters (equivalent to `string`)
 - `:static`  : a fixed, 0-decimal long numberic type (equivalent to `int`)
 - `:dynamic` : a floating-point numerical type (equivalent to `float`)
 - `:error`   : a special type for pre-defined errors (equivalent to `Exception`s)
 - `:null`    : no value at all

Corky also supports structures:
 - `:list@<type>` : an array of values with the same type (equivalent to `array`)
 - `:dict`        : a dictionary containing properties (equivalent to `Object`) **Can't contain functions**
 - `:obj`         : defined an object (equivalent to `Object`) **Must contain the function `:text`**

**Basic syntax**

The syntax is really basic:

Everything followed by `:` is seen as a token. To avoid it of being interpreted, use `::`, like `::this`

Not everything will be evaluated as such!

For example, using :likethis won't cause an error, because it doesn't exist.

Only recognized commands will be parsed.

To separate parameters or sub-commands, the `@` char is used.

You can check the file `examples/hello_world.cork` to see how to make a runnable file.

**Constants**

Constants have a special structure.

They are the most important part of Corky and the base of all types.

To create a constant, use `:cons@<value>`.

The type is guessed based on the provided data: `:cons@"this is a :text "` and `:cons@0` is a `:static` constant (`:cons@0.0` would make a `:dynamic` constant). These can be evaluated right away since they won't change during the execution.

You **must** create constants for everything! It's the only way!

**Pre-defined constants**

There is a number of pre-defined constants:

 - `:compiler` 		- Contains information about the compiler it is running in
  - `@system` 		- OS name and version
  - `@file` 		- Name of the file being read
  - `@version` 		- Version number (E.g.: `cons@1.0`)
 - `:version` 		- Alias for `:compiler@version`
 - `:null`			- Constant of type `:null`
 - `:true` 			- Equivalent to `:cons@1`
 - `:false`			- Equivalent to `:cons@0`
 - `:scope@depth`	- Current depth
 - `:scope@maxdepth`- Maximum depth
 - `:args@~<num>` 	- Constant `:list` with all the arguments of a function.<br>
 	On the global `:scope`, this will be a `:dict` with all the passed arguments
 - `:this` 			- This constant is **only** available inside `:func` that belong to a certain `:obj`
 - `:pragmas@<name>` - Contains all the values of all the default pragmas.<br>
  You can always set pragmas to modules, which have to be accessed as `:pragmas:module:<module>:<pragma>`.

**Variables**

A variable is defined by a tilde (`~`) AND MUST be followed by a SEQUENTIAL integer number. There are no names for variables!

It uses the following structure: `:define:<data-type>:~<number>` with an optional `:store:cons@<value>` matching the same type.

The final result would be like this:

    :define:text:~0 :store:cons@"Nice no?"
    :define:static:~1:store:cons@123

Whitespace doesn't matter: it can be safely removed.

The `:store` command can be only used with the `:define` command or by itself, specifying a variable and the value to use that matches the same type.

**Output**

Output is created using the `:echo` or `:echo:format`. The later is used to output formatted text (like C's `printf()` function).

Everything can generate output, even `:obj`'s!

Structures
==========

Corky has a few different structures, all of which have different uses.

**`:scope` blocks**

`:scope` blocks are used for many things: to create functions, in-line blocks, structures, decision blocks and loops.

When a `:scope` is created, the variables inside it won't be exposed outside. However, using the syntax `:^`, you can access the variables on the parent `:scope`.

To end a scope, use `:end:scope` or `:end@scope`.

Variables inside these blocks aren't exposed outside (they are self-contained).

**Inline `:scope` blocks**

You can throw a `:scope` block nearly anywhere!

An example:

    :define:static:~0
    :scope
     :define:text:~0:store:cons@"This won't cause errors"
    :end:scope
    :echo:~0 < echoes `null`

**Functions**

Functions can be created using the `:define` instruction.

Each function can have multiple parameters, like so:

    :define	< starts the definition
     :func	< starts to define a function
     :&0 	< function identifier
     :fact	< optional function name

Then you need to set the parameters for the function:

    :param	< parameter keyword
     :ref	< optional - reference
     :opt	< optional - defines the parameter as optional
     :static< data type
     :~0	< optional - SEQUENTIAL parameter name

The unnamed values can be accessed though the `:args@~<num>` constant.

Functions can accept more parameters than the ones defined.

Example of a function:

    :define:func:&0:optname
     :param:static:~0
     :scope
      :return:~0 < returns the parameter ~0
     :end:scope

Also, recursion is made really easy:

    :&-1
    :^&-1
    :^&<func name>

All those refer to the same function: the function your `:scope` is in.

**Inline/anonymous/lambda functions**

To create an inline function, simply pass a scope to any function that needs a function as a parameter.

The data types will have to be cheched inside the function before using the arguments.

Decision structures and loops
=============================

**`:case` blocks**

A `:case` block works like an `if()` in other languages.

You need to provide at least 1 value to be checked.

Here is an example:

    :case:~0
        :scope
            :echo:~0
            :echo:cons@' <-- is equivalent to :true'
        :end:scope
    :case:default
        :scope
            :echo:~0
            :echo:cons@' <-- is equivalent to :false or :default'
        :end:scope
    :end:case

This is equivalent to the following code, for Javascript, C and the like:

    if(var0)
        print var0,' <-- is equivalent to :true';
    else
        print var0,' <-- is equivalent to :false or :default';
        
 The `:case:default` is an `else` and **cannot have any other instruction following it** except for a `:scope`.
 
 **`:cicle` loop**
 
 The `:cicle` loop is quite similar to a 'regular' `for(;;)` loop:
 
    :cicle:~0:from:cons@0:to:cons@10
    :scope
        :echo:~0
    :end:scope
 
 This is the same as:
 
     for(var i=0; i<10; i++)
     {
         print i;
     }

To go from a positive to a negative increment, swap the values.

**`:cicle:though[...]:into` loop**

This loop is used to move through each element in a `:dict` or `:list`.

You use it like the following:

    :cicle:though:~0:into:~1:~2
    :scope
        :echo:~2 <-- key name, optional
        :echo:' contains '
        :echo:~1 <-- value
    :end:scope

Importing
=========

**Modules**

Almost everything is made of modules.

String manipulation, Math, Comparissons, Convertions, Filesystem access......

To load a module you can simply do like this:

    :load@<module name> :store:~0 <-- will be 0 or 1

You can later check if a module is loaded, like this:

    :loaded@<module name> :store:~0 <-- will be 0 or 1

If a module isn't being used, it's recommened to:

    :unload@<module name>

None of this methods will raise an `:error`.


Pragmas
========

There are multiple pragmas that change the way that the language behaves.

You **MUST** set them all at the top.

You use the syntax `:pragma:<name>[:<value>]` or `:pragma:module:<module>:<pragma>[:<value>]`.<br>
To fetch it, you use the constant `:pragmas`.<br>
The value can only be `:cons@0` or `:cons@1`, being all other values handled as `:cons@1`.

List of all the default `:pragmas`:

 - `:null_as_false`				- Defines `:null` as the same as `:cons@0` (defaults to `:cons@1`).
 - `:text_autoclose`			- Automatically closes a text line, only allowing single-line content.
 - `:error_on_missing_module`	- Raises an `:error` when the module doesn't exist or fails to load
 - `:carry_on_death`			- When an `:error` occurrs, the code won't stop running
 - `:carry_on_type_mismatch`	- When an `:error@mismatch` occurrs, the code won't stop and an implicit convertion will happen (defaults to `:cons@1`)

`:pragmas` for `:module`s:

 - `:module:text`
 	- `:eol_window`				- Set the `:text@eol` as `:call:text@from_code:cons@13:cons@10:store:text@eol` on compile-time.
 - `:module:dynamic`
	 - `:mismatch_to_0`			- Defines if a mismatched value will be converted to `:cons@0`.
 - `:module:static`
	 - `:mismatch_to_0`			- Defines if a mismatched value will be converted to `:cons@0`.
