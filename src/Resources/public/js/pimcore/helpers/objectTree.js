pimcore.registerNS("pimcore.plugin.storeExporterDataObject.helpers.objectTree");
pimcore.plugin.storeExporterDataObject.helpers.objectTree = Class.create(pimcore.object.tree, {

    tree: null, 
    initialize: function ($super, parent, name) {
        this.name = name;
        initConfig = {
                rootVisible: false,
                allowedClasses: null,
                loaderBaseParams: {},
                treeId: "pimcore_bundle_storesyndicator_tree_objects" + name,
                treeIconCls: "pimcore_icon_main_tree_object pimcore_icon_material",
                treeTitle: t('data_objects'),
                parentPanel: parent
            }
        $super(initConfig);
    },

    getTree: function (){
        return this.tree;
    },

    //overriding this function as we dont want any object opening or modifying here
    getTreeNodeListeners: function () {
        var treeNodeListeners = {
            'itemclick': this.onTreeNodeClick
        };

        return treeNodeListeners;
    },
    onTreeNodeClick: function(tree, record, item, index, event, eOpts){
        if(record.get("checked")){
            record.set({checked: false});
        } else{
            record.set({checked: true});
        }
    },

    //need this entire function override to disable dragging
    init: function (rootNodeConfig) {

        var itemsPerPage = pimcore.settings['object_tree_paging_limit'];

        rootNodeConfig.text = t("home");
        rootNodeConfig.id = "" +  rootNodeConfig.id;
        rootNodeConfig.allowDrag = false;
        rootNodeConfig.iconCls = "pimcore_icon_home";
        rootNodeConfig.cls = "pimcore_tree_node_root";
        rootNodeConfig.expanded = true;

        this.treeDataUrl = Routing.generate("pimcore_storesyndicator_product_choice_get_tree");
        var store = Ext.create('pimcore.data.PagingTreeStore', {
            autoLoad: true,
            autoSync: false,
            proxy: {
                type: 'ajax',
                url: this.treeDataUrl,
                reader: {
                    type: 'json',
                    totalProperty : 'total',
                    rootProperty: 'nodes'
                },
                extraParams: {
                    limit: itemsPerPage,
                    view: this.config.customViewId,
                    name: this.name
                }
            },
            pageSize: itemsPerPage,
            root: rootNodeConfig
        });


        // objects
        this.tree = Ext.create('pimcore.tree.Panel', {
            selModel : {
                mode : 'MULTI'
            },
            store: store,
            region: "center",
            autoLoad: false,
            iconCls: this.config.treeIconCls,
            cls: this.config['rootVisible'] ? '' : 'pimcore_tree_no_root_node',
            id: this.config.treeId,
            title: this.config.treeTitle,
            autoScroll: true,
            animate: false,
            header: false,
            rootVisible: this.config.rootVisible,
            bufferedRenderer: false,
            border: false,
            listeners: this.getTreeNodeListeners(),
            scrollable: true,
            viewConfig: {
                xtype: 'pimcoretreeview'
            },
            tools: [{
                type: "right",
                handler: pimcore.layout.treepanelmanager.toRight.bind(this),
                hidden: this.position == "right"
            },{
                type: "left",
                handler: pimcore.layout.treepanelmanager.toLeft.bind(this),
                hidden: this.position == "left"
            }]
        });

        store.on("nodebeforeexpand", function (node) {
            pimcore.helpers.addTreeNodeLoadingIndicator("object", node.data.id, false);
        });

        store.on("nodeexpand", function (node, index, item, eOpts) {
            pimcore.helpers.removeTreeNodeLoadingIndicator("object", node.data.id);
        });


        this.tree.on("afterrender", function () {
            this.tree.loadMask = new Ext.LoadMask(
                {
                    target: Ext.getCmp(this.config.treeId),
                    msg:t("please_wait")
                });
        }.bind(this));

        this.config.parentPanel.insert(this.config.index, this.tree);
        this.config.parentPanel.updateLayout();


        if (!this.config.parentPanel.alreadyExpanded && this.perspectiveCfg.expanded) {
            this.config.parentPanel.alreadyExpanded = true;
            this.tree.expand();
        }

    },
});
