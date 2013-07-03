Jsonary.plugins.LinkTableRenderer({
	linkHandler: function (link, submittedData, request) {
		return false;
		console.log(link.href);
	},
	rowOrder: function (data, context) {
		var sortFunctions = [];
		context.uiState.sort = context.uiState.sort || [];
		
		function addSortFunction(sortKey) {
			var direction = sortKey.split('/')[0];
			var path = sortKey.substring(direction.length);
			var multiplier = (direction == "desc") ? -1 : 1;
			sortFunctions.push(function (a, b) {
				var valueA = a.get(path);
				var valueB = b.get(path);
				if (valueA < valueB) {
					return -multiplier;
				} else if (valueA > valueB) {
					return multiplier;
				}
				return 0;
			});
		}
		for (var i = 0; i < context.uiState.sort.length; i++) {
			addSortFunction(context.uiState.sort[i]);
		}
		var indices = [];
		var length = data.length();
		while (indices.length < length) {
			indices[indices.length] = indices.length;
		}
		indices.sort(function (a, b) {
			for (var i = 0; i < sortFunctions.length; i++) {
				var comparison = sortFunctions[i](data.item(a), data.item(b));
				if (comparison != 0) {
					return comparison;
				}
			}
			return 0;
		});
		return indices;
	},
	defaultTitleHtml: function (data, context, columnKey) {
		var result = '<th>';
		context.uiState.sort = context.uiState.sort || [];
		if (columnKey.charAt(0) == "/") {
			result += context.actionHtml(Jsonary.escapeHtml(this.titles[columnKey]), 'sort', columnKey);
			if (context.uiState.sort[0] == "asc" + columnKey) {
				result += ' <span class="json-array-table-sort-asc">up</span>'
			} else if (context.uiState.sort[0] == "desc" + columnKey) {
				result += ' <span class="json-array-table-sort-desc">down</span>'
			}
		} else {
			result += Jsonary.escapeHtml(this.titles[columnKey]);
		}
		return result + '</th>'
	},
	action: function (data, context, actionName, arg1) {
		if (actionName == "sort") {
			var columnKey = arg1;
			context.uiState.sort = context.uiState.sort || [];
			if (context.uiState.sort[0] == "asc" + columnKey) {
				context.uiState.sort[0] = "desc" + arg1;
			} else {
				if (context.uiState.sort.indexOf("desc" + columnKey) != -1) {
					context.uiState.sort.splice(context.uiState.sort.indexOf("desc" + columnKey), 1);
				} else if (context.uiState.sort.indexOf("asc" + columnKey) != -1) {
					context.uiState.sort.splice(context.uiState.sort.indexOf("asc" + columnKey), 1);
				}
				context.uiState.sort.unshift("asc" + arg1);
			}
			return true;
		}
	}
})
.addLinkColumn('edit', '', 'edit', 'save', true)
.addLinkColumn('delete', '', 'delete', 'cancel', false)
.addColumn('/id', '#')
.addColumn('/title', 'Title')
.addColumn('/feasibility', 'Feasibility')
.register(function (data, schemas) {
	return schemas.containsUrl('idea#/definitions/array');
});