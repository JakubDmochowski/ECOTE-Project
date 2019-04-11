<!DOCTYPE html>
<html lang="en-us">
    <head>
        <meta charset="utf-8">
        <meta hhtp-equiv="X-UA-Compatible" content="IE=edge">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <link href="{{ mix('/css/app.css')}}" rel="stylesheet" type="text/css">

        <title>ECOTE PROJECT</title>

    </head>
    <body>
        <div id="app">
        </div>
    </body>
    <script src="{{ mix('/js/app.js')}}"></script>
    <script>
        // Warn if overriding existing method
        if(Array.prototype.equals) {
            console.warn("Overriding existing Array.prototype.equals. Possible causes: New API defines the method, there's a framework conflict or you've got double inclusions in your code.");
        }
        Array.prototype.equals = function (array) {
            if (!array || (this.length != array.length)) {
                return false;
            }

            for (var i = 0, l=this.length; i < l; i++) {
                // Check if we have nested arrays
                if (this[i] instanceof Array && array[i] instanceof Array) {
                    // recurse into the nested arrays
                    if (!this[i].equals(array[i])) {
                        return false;       
                    }
                } else if (this[i] != array[i]) { 
                    // Warning - two different object instances will never be equal: {x:20} != {x:20}
                    return false;   
                }           
            }       
            return true;
        }
        // Hide method from for-in loops
        Object.defineProperty(Array.prototype, "equals", {enumerable: false});

        class Parser {
            constructor () {
                this.callStack = []
                this.current = {
                    discriminant: "",
                    args: [],
                }
                this.stored = "",
                this.state = "ready"
                this.result = []
                //this.buffer = "" //for nested calls to be returned as arguments
                this.parsing = {}
            }

            getCalls (string) {
                this.parsing = {
                    "ready": {
                        "$": (char) => {
                            this.stored = ""
                            this.state = "discriminant"
                        }
                    },
                    "discriminant": {
                        "(": (char) => {
                            this.current.discriminant = this.stored.slice(0, -1)
                            if (!this.callStack.length) {
                                this.stored = ""
                            }
                            this.state = "arguments"
                        },
                    },
                    "arguments": {
                        ",": (char) => {
                            this.current.args.push(this.stored.slice(0, -1))
                            this.stored = ""
                        },
                        "$": (char) => {
                            this.callStack.push(Object.assign({}, this.current))
                            this.current.discriminant = ""
                            this.current.args = []
                            this.state = "discriminant"
                        },
                        ")": (char) => {
                            if (this.callStack.length) {
                                //this.buffer = this.buffer + `$${this.current.discriminant}(${this.current.args.join(",")})`
                                this.current = Object.assign({}, this.callStack.pop())
                            } else {
                                this.current.args.push(this.stored.slice(0, -1))
                                this.stored = ""
                                this.result.push(Object.assign({},this.current))
                                this.current.discriminant = ""
                                this.current.args = []
                                this.state = "ready"
                            }
                        }
                    },
                }
                let str = string
                this.result = []
                while (str.length) {
                    str = this.consume(str)
                }
                if (this.callStack.length || !this.state === 'ready') {
                    return null
                }
                this.callStack = []
                this.state = "ready"
                this.parsing = {}
                return this.result.map(call => {
                    return `$${call.discriminant}(${call.args.join(",")})`
                })
            }

            getArgs (string) {
                this.parsing = {
                    "ready": {
                        "(": (char) => {
                            this.stored = ""
                            this.state = "arguments"
                        }
                    },
                    "arguments": {
                        ",": (char) => {
                            this.current.args.push(this.stored.slice(0, -1))
                            this.stored = ""
                        },
                        "(": (char) => {
                            this.callStack.push('(')
                        },
                        ")": (char) => {
                            if (this.callStack.length) {
                                //this.buffer = this.buffer + `$${this.current.discriminant}(${this.current.args.join(",")})`
                                this.callStack.pop()
                            } else {
                                this.current.args.push(this.stored.slice(0, -1))
                                this.stored = ""
                                this.result.push(Object.assign({},this.current))
                                this.current.args = []
                                this.state = "ready"
                            }
                        }
                    },
                }
                let str = string
                this.result = []
                while (str.length) {
                    str = this.consume(str)
                }
                if (this.callStack.length || !this.state === 'ready') {
                    return null
                }
                this.callStack = []
                this.state = "ready"
                this.parsing = {}
                return this.result[0].args
            }
            
            consume (string) {
                this.stored = this.stored + string[0]
                const func = this.parsing[this.state][string[0]]
                if(func) {
                    func()
                }
                return string.slice(1)
            }
        }

        let parseToString = (root) => {
            let resultStr = ""
            Object.keys(root).forEach(name => {
                resultStr += `#${name}(`
                root[name].args.forEach((arg, index) => {
                    resultStr += arg + (index != root[name].args.length - 1 ? ',' : '')
                })
                resultStr += `) {${root[name].body}}\n`
            })
            return resultStr
        }
        let parseToJSON = (string) => {
            const reg = /\#[^\(]+\(\&\d+(?:,\s*\&\d+)*\)\s*\{[^\}]*\}/g
            const resultObj = {}
            var definitionString;
            string.match(reg).forEach(definitionString => {
                //regex returns "#something(", we trim it to "something" which is name
                const name = definitionString.match(/^\#[^\(]+\(/)[0].slice(1,-1)
                //regex returns "(&1, &2, &3){"
                let argsString = definitionString.match(/\(\&\d+\s*(?:\,\s*\&\d+)*\)\s*\{/)
                let args = []
                let arg;
                argsString = argsString && argsString[0]
                if (argsString) {
                    //iterate over arguments, add each one separately to args table
                    const argsFound = argsString.match(/\&\d+/g)
                    if(argsFound && argsFound.length) {
                        argsFound.forEach(arg => {
                            args.push(arg)
                        })
                    }
                }
                //regex returns "{bodymessage}", we trim it to "bodymessage"
                const body = definitionString.match(/\{[^\{]*\}/)[0].slice(1, -1)
                resultObj[name] = {
                    args: args,
                    body: body,
                }
            })
            return resultObj
        }
        let argReplace = (obj, args = []) => {
            var result = obj.body
            obj.args.forEach((arg, index) => {
                result = result.replace(new RegExp(arg, 'g'), args[index] || "")
            })
            return result
        }
        let getCalls = (string) => {
            return new Parser().getCalls(string)
            // var calls = []
            // const invocations = string.match(/\$[^\(]+\([^\)]*\)/g) || []
            // invocations.forEach(call => {
            //     calls.push(call)
            // })
            // return [] //calls
        }
        let getCallsWithInvoker = (invoker, string) => {
                let result = []
                getCalls(string).forEach(call => {
                    let callobj = {}
                    callobj[invoker] = call
                    result.push(callobj)
                })
                return result
        }
        let getName = (call) => {
            //regex returns "#something(", we trim it to "something"
            const match = call.match(/^[\$\#][^\(]+\(/)
            return match && match[0] && match[0].slice(1, -1)
        }
        let getArgs = (string) => {
            //get call fragment with arguments "(...)",
            //then from that get arguments separated by commas and ending with a closing bracket
            return new Parser().getArgs(string)
            // var result = []
            // const match = string.match(/\([^\)]*\)/)
            // const trimmedArgsString = match && match[0] && match[0].slice(1, -1)
            // result = trimmedArgsString && trimmedArgsString.match(/[^\,\)]+/g) || []
            // console.log("getArgs: ", JSON.stringify(result))
            // return result
        }
        let getProperties = (call) => {
            const invoker = Object.keys(call)[0]
            const callName = getName(call[invoker]);
            const callArgs = getArgs(call[invoker]);
            return [invoker, callName, callArgs]
        }
        let isInfiniteLooped = (root) => {
            let macrocallsToCheck = []
            Object.keys(root).forEach(name => {
                const calls = getCalls(root[name].body)
                root[name].canCall = {}
                calls.forEach(call => {
                    const callName = getName(call)
                    root[name].canCall[callName] = []
                    root[name].canCall[callName] = root[name].canCall[callName].concat([getArgs(call)])
                })
                calls.forEach(call => {
                    let callobj = {}
                    callobj[name] = call
                    macrocallsToCheck.push(callobj)
                })
            })
            console.log("Input structure", JSON.stringify(root, null, '\t'))
            while(macrocallsToCheck.length) {
                let added = false
                call = macrocallsToCheck.pop()
                const [invoker, callName, callArgs] = getProperties(call)
                if (!Object.keys(root[invoker].canCall).includes(callName)) {
                    root[invoker].canCall[callName] = []
                }
                if (!root[invoker].canCall[callName].find(args => args.equals(callArgs))) {
                    root[invoker].canCall[callName].push(callArgs)
                    added = true
                }
                if (Object.keys(root[invoker].canCall).includes(callName)) {
                    if(Object.keys(root[invoker].canCall).includes(invoker)) {
                        if(root[invoker].canCall[invoker].find(args => args.equals(callArgs))) {
                            if (!added) {
                                console.log(`Infinite Recursion found: ${invoker} can call:`, call[invoker])
                                console.log("Final structure",root)
                                return true
                            }
                        }
                    }
                }
                //calls originated from invoker
                let newMacrocalls = getCallsWithInvoker(invoker, argReplace(root[callName], callArgs))
                let filtered = newMacrocalls.filter(call => {
                    const [invoker, callName, callArgs] = getProperties(call)
                    if (invoker === callName) {
                        return true
                    }
                    return !(root[invoker] &&
                        root[invoker].canCall &&
                        root[invoker].canCall[callName] &&
                        root[invoker].canCall[callName].find(args => args.equals(callArgs)))
                })
                if (filtered.length) {
                    console.log(`New Macrocall${filtered.length === 1 ? '' : 's'} found`,JSON.stringify(filtered, null, '\t'))
                }
                macrocallsToCheck = macrocallsToCheck.concat(filtered)
                //calls originated from called macrocalls
                newMacrocalls = getCallsWithInvoker(callName, argReplace(root[callName], callArgs))
                filtered = newMacrocalls.filter(call => {
                    const [invoker, callName, callArgs] = getProperties(call)
                    if (invoker === callName) {
                        return true
                    }
                    return !(root[invoker] &&
                        root[invoker].canCall &&
                        root[invoker].canCall[callName] &&
                        root[invoker].canCall[callName].find(args => args.equals(callArgs)))
                })
                if (filtered.length) {
                    console.log(`New Macrocall${filtered.length === 1 ? '' : 's'} found`,JSON.stringify(filtered, null, '\t'))
                }
                macrocallsToCheck = macrocallsToCheck.concat(filtered)
            }
            console.log("Final structure",root)
            return false
        }
        const testCases = [
            {
                'ONE': {
                    args: [
                        "&1",
                        "&2",
                    ],
                    body: "ZUZIA &1 $ONE(TOMEK, &1)     &2",
                },
            },
            {
                'ONE': {
                    args: [
                        "&1",
                        "&2",
                    ],
                    body: "ZUZIA &1 $TWO(TOMEK)     &2",
                },
                'TWO': {
                    args: [
                        "&1",
                        "&2",
                    ],
                    body: "ZUZIA &1 &2 $ONE(MAREK,&1)",
                }
            },
            {
                'ONE': {
                    args: [
                        "&1",
                        "&2",
                    ],
                    body: "$THREE($TWO(TOMEK))",
                },
                'TWO': {
                    args: [
                        "&1",
                    ],
                    body: "&1 $THREE(MAREK)",
                },
                'THREE': {
                    args: [
                        "&1",
                    ],
                    body: "$FOUR(&1))",
                },
                'FOUR': {
                    args: [
                        "&1",
                    ],
                    body: "$ONE(&1)",
                }
            },
            {
                'ONE': {
                    args: [
                        "&1",
                    ],
                    body: "&1 $THREE($TWO(TOMEK))",
                },
                'TWO': {
                    args: [
                        "&1",
                    ],
                    body: "$ONE(&1)",
                },
                'THREE': {
                    args: [
                        "&1",
                    ],
                    body: "&1",
                }
            },
            {
                'ONE': {
                    args: [
                        "&1",
                        "&2",
                    ],
                    body: "$TWO($THREE(TOMEK))",
                },
                'TWO': {
                    args: [
                        "&1",
                    ],
                    body: "$THREE(&1)",
                },
                'THREE': {
                    args: [
                        "&1",
                    ],
                    body: "&1",
                }
            },
            {
                'ONE': {
                    args: [
                        "&1",
                        "&2",
                    ],
                    body: "$TWO($THREE($TWO($TWO(TOMEK))), $TWO(NO), $THREE(TAK))",
                },
                'TWO': {
                    args: [
                        "&1",
                    ],
                    body: "$THREE(&1)",
                },
                'THREE': {
                    args: [
                        "&1",
                    ],
                    body: "&1",
                }
            },
            {
                'ONE': {
                    args: [
                        "&1",
                        "&2",
                    ],
                    body: "$TWO(TOMEK), $TWO(NO), $THREE(TAK))",
                },
                'TWO': {
                    args: [
                        "&1",
                    ],
                    body: "$THREE(&1)",
                },
                'THREE': {
                    args: [
                        "&1",
                    ],
                    body: "&1",
                }
            },
            {
                'ONE': {
                    args: [
                        "&1",
                        "&2",
                    ],
                    body: "$TWO(TOMEK) $THREE($TWO(TOMEK))",
                },
                'TWO': {
                    args: [
                        "&1",
                    ],
                    body: "&1 $THREE(MAREK)",
                },
                'THREE': {
                    args: [
                        "&1",
                    ],
                    body: "$FOUR(&1))",
                },
                'FOUR': {
                    args: [
                        "&1",
                    ],
                    body: "&1",
                }
            }
        ]
        function htmlToElement(html) {
            var template = document.createElement('template');
            html = html.trim(); // Never return a text node of whitespace as the result
            template.innerHTML = html;
            return template.content.firstChild;
        }
        testCases.forEach((testCase, index) => {
            var newElem = htmlToElement(`
                <div class="ml-3 mt-3">
                    <div class="ml-3">
                    <span
                        class="font-weight-bold"
                    >
                        TestCase${index} macrodefinitions:
                    </span>
                    </div>
                    <div class="mt-1">
                        <span
                        >
                        ${parseToString(testCase).replace(/\n/g, "<br>")}
                        </span>
                    </div>
                    <div class="mt-1">
                        <span
                        >
                        Has infinite recursion:
                        </span>
                    </div>
                    <span
                        id="result"
                        class="font-weight-bold"
                    >
                    ${isInfiniteLooped(testCase)}
                    </span>
                </div>
            </div>`)
            document.querySelector('#app').appendChild(newElem)
        })
        var footer = htmlToElement(`\
            <div style="margin-top: 2rem;">\
                To see more details, look in the console log\
            </div>\
        `)
        document.querySelector('#app').appendChild(footer)
    </script>
</html>