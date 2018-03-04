//==============================
//          TEMPLATES
//==============================

function stepsTemplate(model) {
    var html = "";
    html += '<div id="steps">';
    if (model && model.steps) {
        model.steps.forEach(function(step){
            html += '<div class="step status-' + (step.status ? step.status.toLowerCase() : 'unknown') + ' ' + (step.name === 'enter-maintenance' ? 'section-start-step' : '') + '">';
            html += '<div class="text ' + (step.name === 'enter-maintenance' || step.name === 'exit-maintenance' ? 'bold' : '') + '">';
            html += step.text;
            if (step.updateID) {
                html += '- ID: ' + step.updateID;
            }
            html += '</div>';
            html += '<div class="status">';
            if (step.status === 'PENDING') {
              html += '<i class="fa fa-circle-o-notch fa-spin fa-fw"></i>';
            }
            else if (step.status === 'COMPLETED') {
              html += '&check; OK';
            }
            else if (step.status === 'FAILED') {
              html += step.error ? step.error.name : 'PROBLEM';
            }
            html += '</div>';
            html += '</div>';
        });
    }
    else {
        html += '<div class="step status-pending">';
        html += '<div class="text">...</div>';
        html += '<div class="status">';
        html += '<i class="fa fa-circle-o-notch fa-spin fa-fw"></i>';
        html += '</div>';
        html += '</div>';
    }
    html += '</div>';
    return html;
}

function errorTemplate(model) {
    var html = "";
    html += '<div>';
    html += '<div id="update-error" class="selectable">';
    html += '<strong>' + model.error.name + '</strong>';
    if (model.text) {
        html += '<p>' + model.text + '</p>';
    }
    if (model.error.data) {
        html += '<pre>' + JSON.stringify(model.error.data, null, 2) + '</pre>';
    }
    if (model.error.lastPhpError) {
        html += '<p class="small">Last PHP error:</p>';
        html += '<pre>' + JSON.stringify(model.error.lastPhpError, null, 2) + '</pre>';
    }
    html += '<p>';
    html += '<button data-action="retry-update"><i class="fa fa-repeat"></i> Try again</button>';
    html += '</p>';
    html += '</div>';
    html += '</div>';
    return html;
}

//==============================
//            VIEW
//==============================

function View($main, controller) {
    this.$main = $main;
    this.controller = controller;
    this.controller.view = this;
}

View.prototype.init = function() {
    this.updateSteps();
    this.$main.on("click", "#finish-info button", this.onFinishButtonClick.bind(this));
    this.$main.on("click", "[data-action=retry-update]", this.onRetryButtonClick.bind(this));
    this.controller.onViewReady();
};

View.prototype.updateSteps = function(steps) {
    var $steps = stepsTemplate({steps: steps});
    this.$main.find("#steps").replaceWith($steps);
};

View.prototype.showUpdateError = function(error) {
    var $error = errorTemplate({error: error});
    this.$main.find("#update-error-placeholder").html($error);
};

View.prototype.showInitError = function(error) {
    this.$main.find("#steps").hide();
    this.showUpdateError(error);
};

View.prototype.resetView = function() {
    this.$main.find("#update-error-placeholder").html("");
    var $steps = this.$main.find("#steps");
    $steps.html("");
    $steps.show();
};

View.prototype.showFinishInfo = function() {
    this.$main.find("#finish-info").fadeIn("slow");
};

View.prototype.onFinishButtonClick = function() {
    this.controller.onViewForceRestart();
};

View.prototype.onRetryButtonClick = function() {
    this.controller.onViewRetryUpdate();
};

//==============================
//           UTILS
//==============================

function PromiseUtilsInfinity(guardian, func) {
    var next = function() {
        if (!guardian.value) {
            return Promise.resolve();
        }
        return Promise.resolve().then(function() {
            return func();
        })
        .then(function() {
            return next();
        });
    };
    return next();
}

function PromiseDelay(delay) {
    return new Promise(function(resolve) {
        setTimeout(resolve, delay);
    });
}

function simpleDeepClone(val) {
    return JSON.parse(JSON.stringify(val));
}

function simpleIsEqual(a, b) {
    return JSON.stringify(a) === JSON.stringify(b);
}

//==============================
//            API
//==============================

function Api(token) {
    this.token = token;
}

Api.prototype.request = function(method) {
    return $.getJSON("?_=" + new Date().getTime() + "&method=" + method + "&token=" + this.token);
};

Api.prototype.updaterStartUpdate = function() {
    return this.request("startUpdate");
};

Api.prototype.updaterGetStatus = function() {
    return this.request("getStatus");
};

//==============================
//         CONTROLLER
//==============================

function Controller(updateID, loginUrl, updaterApi) {
    this.updateID = updateID;
    this.loginUrl = loginUrl;
    this.updaterApi = updaterApi;
    this.steps = [];
}

Controller.prototype.onError = function(e) {
    console.log("Error", e, e ? e.stack : null);
    alert("An error occurs, check browser console for more information");
};

Controller.prototype.init = function() {
};

Controller.prototype.onViewForceRestart = function() {
    document.location = this.loginUrl;
};

Controller.prototype.onViewReady = function() {
    this.initUpdate();
};

Controller.prototype.onViewRetryUpdate = function() {
    this.view.resetView();
    this.initUpdate();
};

Controller.prototype.initUpdate = function() {
    var that = this;
    var guardian = {value: true};
    PromiseUtilsInfinity(guardian, function() {
        return that.updaterApi.updaterGetStatus()
        .then(function(data) {
            if (guardian.value && data) {
                that.refreshStepsView(data);
            }
        })
        .catch(function(error) {
            if (guardian.value) {
                console.log("Fetch steps error", error);
            }
        })
        .then(function() {
            return PromiseDelay(250);
        });
    });
    that.updaterApi.updaterStartUpdate()
    .then(function(data) {
        guardian.value = false;
        if (data && data.status) {
            that.refreshStepsView(data.status.steps);
        }
        if (data && data.error) {
            console.log("Update error", data.error);
            that.view.showUpdateError(data.error);
        }
        else {
            that.view.showFinishInfo();
        }
    })
    .catch(function(error) {
        guardian.value = false;
        that.onError(error);
    });
};

Controller.prototype.refreshStepsView = function(steps) {
    if (this.checkSteps(steps)) {
        this.steps = steps;
        this.view.updateSteps(this.getExtendedStepsInfo(this.steps));
    }
};

Controller.prototype.checkSteps = function(steps) {
    if (!this.steps) {
        return true;
    }
    return !simpleIsEqual(this.steps, steps);
};

Controller.prototype.getExtendedStepsInfo = function(steps) {
    var result = [];
    var texts = {
        "init": "Initializing",
        "download-zip": "Downloading package",
        "validate-zip": "Validating package",
        "extract-files": "Extracting files",
        "validate-files": "Validating files",
        "enter-maintenance": "Entering maintenance mode",
        "make-backup": "Making backup of data, config etc",
        "copy-files": "Copying new files",
        "exit-maintenance": "Leaving maintenace mode"
    };
    steps.forEach(function(step) {
        result.push({
            name: step.name,
            status: step.status,
            error: step.error ? simpleDeepClone(step.error) : null,
            text: texts[step.name] || step.name
        });
    });
    if (result.length > 0 && this.updateID) {
        result[0].updateID = this.updateID;
    }
    return result;
};

//==============================
//            VIEW
//==============================

$(function() {
    var $main = $("body");
    var controller = new Controller(UPDATE_ID, LOGIN_URL, new Api(TOKEN));
    var view = new View($main, controller);
    Promise.resolve().then(function() {
        return controller.init();
    })
    .then(function() {
        return view.init();
    })
    .catch(function(e) {
        controller.onError(e);
    });
});
