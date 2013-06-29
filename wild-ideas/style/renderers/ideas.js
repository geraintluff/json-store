Jsonary.plugins.TableRenderer.register({
	columns: [
		"actions",
		"/title",
		"/feasibility"
	],
	titles: {
		"actions": "",
		"remainder": ""
	},
	rowRenderHtml: function (data, context) {
		var result = '';
		if (context.uiState.editData) {
			result += this.defaultRowRenderHtml(context.uiState.editData, context);
		} else {
			result += this.defaultRowRenderHtml(data, context);
		}
		if (context.uiState.expand) {
			result += '<td class="json-array-table-full" colspan="' + this.columns.length + '">';
			result += context.renderHtml(data);
			result += '</td>';
		}
		return result;
	},
	defaultCellRenderHtml: function (data, context) {
		var result = '<td style="position: relative">';
		result += context.renderHtml(data);
		if (data.readOnly()) {
			result += context.parent.actionHtml('<div style="position: absolute; left: 0; right: 0; top: 0; bottom: 0"></div>', 'expand');
		}
		return result + '</td>';
	},
	cellRenderHtml: {
		"actions": function (data, context) {
			var result = '<td>';
			if (context.parent.uiState.editData) {
				result += context.parent.actionHtml('save', 'save');
			} else {
				if (data.readOnly()) {
					var editLink = data.getLink('edit');
					if (editLink) {
						result += context.parent.actionHtml('edit', 'edit');
					}
				}
			}
			return result + '</td>';
		}
	},
	rowAction: function (data, context, actionName) {
		if (actionName == "expand") {
			if (context.uiState.expand) {
				delete context.uiState.expand;
			} else {
				context.uiState.expand = true;
			}
		} else if (actionName == "edit") {
			context.uiState.editData = data.editableCopy();
		} else if (actionName == "save") {
			var editLink = data.getLink('edit');
			if (editLink) {
				editLink.follow(context.uiState.editData, false);
			}
			delete context.uiState.editData;
		}
		return true;
	},
	filter: function (data, schemas) {
		return schemas.containsUrl('json/schemas/idea#/definitions/array');
	}
});