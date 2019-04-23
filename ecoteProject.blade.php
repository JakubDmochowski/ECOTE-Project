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
        <div class="mt-3 ml-3">
            <span class="font-weight-bold">
                Select a file with testcases as input
            </span>
            <input
                type="file"
                id="myFile"
            >
        </div>
        <div class="row p-3">
            <div class="col">
                INPUT PREVIEW:
            </div>
        </div>
        <div class="row pl-3">
            <div class="col">
                <textarea
                    rows="5"
                    style="width: 100%"
                    id="inputFile"
                >
                </textarea>
            </div>
        </div>
        <span>Click button to recalculate tests from textarea</span>
        <button onclick="rewriteOutput()">Calculate</button>
        <div class="row p-3">
            <div class="col">
                RESULTS:
            </div>
        </div>
        <div id="app">
        </div>
    </body>
    <script src="{{ mix('/js/app.js')}}"></script>
    <script>
//----------------------------------------------------- HELPER FUNCTIONS + PARSER -----------------------------------------------------
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
                return this.result && this.result[0] && this.result[0].args
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
            Object.keys(root).forEach(instance => {
                resultStr += `#${root[instance].descriptor}(`
                root[instance].args.forEach((arg, index) => {
                    resultStr += arg + (index != root[instance].args.length - 1 ? ',' : '')
                })
                resultStr += `) {${root[instance].body}}\n`
            })
            return resultStr
        }
        let parseToJSON = (string) => {
            console.log(string)
            const reg = /\#[^\(]+\((?:\&\d+(?:,\s*\&\d+)*)?\)\s*\{[^\}]*\}/g
            const resultObj = []
            var definitionString;
            const argString = string.match(reg)
            if (argString) {
                argString.forEach(definitionString => {
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
                    resultObj.push({
                        descriptor: name,
                        args: args,
                        body: body,
                    })
                })
            }
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
        }
        let getCallsWithInvoker = (invoker, string) => {
                let result = []
                getCalls(string).forEach(call => {
                    result.push({invoker, call})
                    // let callobj = {}
                    // callobj[invoker] = call
                    // result.push(callobj)
                })
                return result
        }
        let getName = (call) => {
            //regex returns "#descriptor(...)", we trim it to "descriptor"
            const match = call.match(/^[\$\#][^\(]+\(/)
            return match && match[0] && match[0].slice(1, -1)
        }
        let getArgs = (string) => {
            return new Parser().getArgs(string)
        }
        let getProperties = (call) => {
            const invoker = Object.keys(call)[0]
            const callName = getName(call[invoker]);
            const callArgs = getArgs(call[invoker]);
            return [invoker, callName, callArgs]
        }
//-------------------------------------------- ALGORITHM FOR INFINITE RECURSION FINDING --------------------------------------------------

        let isInfiniteLooped = (root) => {
            let macrocallsToCheck = []
            root.forEach(definition => {
                const invoker = `$${definition.descriptor}(${definition.args.join(',')})`
                const calls = getCalls(definition.body)
                calls.forEach(call => {
                    macrocallsToCheck.push({invoker, call})
                })
            })
            while (macrocallsToCheck.length) {
                const instanceChecked = macrocallsToCheck.pop()
                const invoker = instanceChecked.invoker
                const call = instanceChecked.call
                const invokerDescriptor = getName(invoker)
                const invokerArgs = getArgs(invoker)
                let invokerInstance = root.find(e => e.descriptor === invokerDescriptor && e.args.equals(invokerArgs))
                if (invokerInstance) {
                    //found instance of invoker
                    if (!invokerInstance.canCall) {
                        //initialize canCall structure
                        invokerInstance.canCall = []
                    }
                    if (!invokerInstance.canCall.find(c => c === call)) {
                        //there is no such call in cancall structure of this invocation
                        invokerInstance.canCall.unshift(call)
                    } 
                } else {
                    //if invoker is not present in the list, create one using body of definition we already know.
                    invokerInstance = root.find(e => e.descriptor === invokerDescriptor)
                    if (!invokerInstance) {
                        throw "SOMETHING IS HEAVILY BROKEN"
                    }
                    
                    let newInstance = {
                        descriptor: invokerDescriptor,
                        args: invokerArgs,
                        body: argReplace(invokerInstance, invokerArgs),
                    }
                    root.push(newInstance)
                }
                if (invokerInstance.descriptor === getName(call) && invokerInstance.args.equals(getArgs(call))) {
                    return `Infinite Recursion found: ${invoker} can call:${call}`
                }
                let callInstance = root.find(e => e.descriptor === getName(call) && e.args.equals(getArgs(call)))
                if (!callInstance) {
                    callInstance = root.find(e => e.descriptor === getName(call))
                }
                //calls originated from invoker
                let newMacrocalls = getCallsWithInvoker(invoker, argReplace(callInstance, getArgs(call) || []))
                newMacrocalls.forEach(call => {
                    macrocallsToCheck.unshift(call)
                })
                //calls originated from called macrocalls
                newMacrocalls = getCallsWithInvoker(call, argReplace(callInstance, getArgs(call) || []))
                newMacrocalls.forEach(call => {
                    macrocallsToCheck.unshift(call)
                })
            }
            return false
        }

//-------------------------------------------- INPUT/OUTPUT SECTION --------------------------------------------------
        var input = document.getElementById("myFile");
        var output = document.getElementById("inputFile");
        let testCases = null
        input.addEventListener("change", function () {
            if (this.files && this.files[0]) {
                var myFile = this.files[0];
                var reader = new FileReader();
                
                reader.addEventListener('load', function (e) {
                    output.textContent = e.target.result;
                    rewriteOutput()
                });
                
                reader.readAsBinaryString(myFile);
            }
        });
        function rewriteOutput () {
            const string = document.getElementById("inputFile").value
            let tests = string.split('--;')
            tests = tests.reduce((acc, curr) => {
                if (curr) {
                    acc.push(parseToJSON(curr.trim()))
                }
                return acc
            }, [])
            writeToOutput(tests)
        }
        function htmlToElement(html) {
            var template = document.createElement('template');
            html = html.trim(); // Never return a text node of whitespace as the result
            template.innerHTML = html;
            return template.content.firstChild;
        }
        function writeToOutput(testCases) {
            document.querySelector('#app').innerHTML = ''
            testCases.forEach((testCase, index) => {
                var newElem = htmlToElement(`
                    <div class="ml-3 mt-3">
                        <div class="ml-3">
                        <span
                            class="font-weight-bold"
                        >
                            TestCase #${index} macrodefinitions:
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
        }
    </script>
</html>